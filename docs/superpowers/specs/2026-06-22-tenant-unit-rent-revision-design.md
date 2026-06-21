# 区画詳細からの家賃改定 入口追加 設計書

- 作成日: 2026-06-22
- 対象: テナント管理で家賃（賃料）改定が **契約詳細画面からしか** 行えない問題を、**区画詳細画面にも入口を追加** して解消する。
- 種別: 機能追加（導線/UX）。改定の入力画面・処理ロジック・`RentRevision` 履歴・スキーマは **一切変更しない**。
- 関連: STEP 7 賃料収入履歴・投資回収（区画詳細 `UnitController::show` 周辺）と同じ区画詳細ページに同居。

## 1. 背景・問題

賃料改定は **契約（Contract）単位** の操作で、現状の入口は契約詳細 `tenant.contracts.show` の「賃料改定」ボタン（`isActive()` かつ `isExecutive()` のみ表示）だけ。
一方、現場では区画詳細 `tenant.units.show` を起点に作業することが多いが、区画詳細には改定の入口が無い。さらに区画詳細から契約詳細へのリンクも無効化（コメントアウト）されたままで、契約詳細へ辿り着く導線も切れている。

区画詳細は既に `$activeContract`（= `Unit::activeContract`、`->where('status','active')` 限定の HasOne）を読み込んでいるため、**改定処理を作り直さず、既存の改定フローへの入口を足すだけ** で実現できる。

## 2. 方針

- 改定の入力画面（`tenant/contracts/revise.blade.php`）・処理（`ContractController::revise`）・履歴（`RentRevision`）は **完全再利用**。
- 区画詳細に「賃料改定」ボタンを追加し、既存ルート `tenant.contracts.revise` へ遷移。
- 改定の起点が区画のときは **改定後も区画詳細に戻す** ため、共有フローに **`return_to` フラグを1つだけ** 通す。`return_to=unit` のときのみ区画詳細へ、それ以外（不在含む）は **従来どおり契約詳細** へ。
- あわせて、区画詳細の **無効化されている「契約詳細を見る →」リンクを有効化**。
- 新規ルート・新規テーブル・新規画面・スキーマ変更は **なし**。

## 3. 設計

### 3.1 `ContractController`（2メソッドのみ小改修）

- `showRevise(Contract $contract)` → `showRevise(Request $request, Contract $contract)` に変更。
  - `$returnTo = $request->query('return_to') === 'unit' ? 'unit' : 'contract';` を算出し、ビューへ `compact('contract', 'returnTo')` で渡す。
  - 既存の `isTerminated()` ガード・`$contract->load(['property','unit','customer'])` は不変（`unit` は既にロード済み＝戻り先算出に使える）。
- `revise(Request $request, Contract $contract)`（POST）:
  - バリデーション・トランザクション（`RentRevision::create` ＋ `$contract->update`）は **不変**。
  - リダイレクトのみ分岐: `$request->input('return_to') === 'unit'` のとき
    `redirect()->route('tenant.units.show', $contract->unit)->with('success', "区画「{$contract->unit->display_name}」の賃料改定を実行しました。")`、
    それ以外は **従来どおり** `tenant.contracts.show` へ（メッセージ文言も従来のまま）。

### 3.2 `resources/views/tenant/contracts/revise.blade.php`

- 先頭で `@php $isFromUnit = ($returnTo ?? 'contract') === 'unit'; $backUrl = $isFromUnit ? route('tenant.units.show', $contract->unit) : route('tenant.contracts.show', $contract); @endphp` 相当を用意。
- **パンくず**を分岐:
  - `unit`: テナント管理 › {物件名}（`tenant.properties.show`）› 区画: {display_name}（`tenant.units.show`）› 賃料改定
  - 既定: テナント管理 › 契約一覧 › {contract_number} › 賃料改定（現状のまま）
- **戻るリンク**（ページ上部）と **キャンセルボタン**（フォーム下部）の `href` を `$backUrl` に。文言も `unit` のとき「区画詳細に戻る」/ 既定「契約詳細に戻る」。
- フォーム内（`@csrf` 直後）に `<input type="hidden" name="return_to" value="{{ $returnTo ?? 'contract' }}">` を追加。
- 改定フォームの input 群・「現在の費用」表示・経営層告知は **不変**。

### 3.3 `resources/views/tenant/units/show.blade.php`

- 「現在の契約条件」カード（`@if($unit->status === Occupied && $activeContract)` ブロック内）のタイトル行を flex 化し、右側に **「賃料改定」ボタン** を追加。
  - 表示条件: `@if(auth()->user()->role->isExecutive())`（契約詳細と同一の認可）。
  - リンク先: `route('tenant.contracts.revise', ['contract' => $activeContract, 'return_to' => 'unit'])`。
  - スタイル: units/show のボタン慣習（inline style）に合わせたアンバー系（`color:#b45309; border:1px solid #fde68a; background:#fff` 等）。amber 系 Tailwind クラスはビルド済み確認済みのため、必要なら `border-amber-200 text-amber-700 hover:bg-amber-50` でも可。
- 「現在の契約」タブ内のコメントアウト済みリンクを有効化:
  `<a href="{{ route('tenant.contracts.show', $activeContract) }}" class="text-sm text-emerald-600 hover:text-emerald-700 font-semibold">契約詳細を見る →</a>`。

## 4. 安全性 / エッジケース

- `return_to` は **`unit` 以外すべて契約詳細扱い** → 任意値が来てもオープンリダイレクトにならない。戻り先は `$contract->unit`（モデルリレーション）でユーザー入力 ID を使わず IDOR なし。
- 認可は二重: ボタンを `isExecutive()` で非表示 ＋ ルートが `role:executive` ミドルウェア配下（`web.php`）。非経営層は URL 直叩きでも 403。
- `$activeContract` は active 限定なので解約済みは入口に出ない。`revise()`/`showRevise()` の既存 `isTerminated()` ガードも残置。
- **契約一覧起点の既存動作は完全に不変**（`return_to` 不在 → `'contract'` 既定 → 従来リダイレクト・従来パンくず・従来戻り先）。
- ルート定義は変更なし（`return_to` は GET クエリ＋POST hidden のみ）。新規 PHP クラス追加も無いため `composer dump-autoload` 不要。

## 5. 非対象（YAGNI）

- 区画専用の改定ルート/コントローラ/ビューの新設（重複のため不採用。既存フロー再利用）。
- 改定対象を区画の「過去契約」や複数契約へ拡張すること（現状どおり active 契約のみ）。
- 改定の入力項目・計算・履歴仕様の変更。

## 6. テスト方針（main repo で `vendor/bin/phpunit`）

- 既存の改定機能が **回帰しない**: `return_to` 不在で改定実行 → `tenant.contracts.show` にリダイレクト、`RentRevision` が1件作成され契約費用が更新される。
- `return_to=unit` で改定実行 → `tenant.units.show`（`$contract->unit`）にリダイレクトされる。
- 非経営層（manager/staff）が `tenant.contracts.revise`（GET/POST）にアクセスすると 403。
- ビュー検証: 区画詳細（入居中・経営層）に賃料改定ボタンと契約詳細リンクが描画される / 空室時はボタン非表示。
- 静的検証: 変更 Blade を `php -l`（コンパイル後）。`view:cache` ＋ 各 `storage/framework/views/*.php` の `php -l`（Bug #26 同型の多行 `@json` は本件で不使用だが、ビュー分岐追加のため一応確認）。

## 7. 変更ファイル一覧（3ファイル）

| ファイル | 変更内容 |
|---|---|
| `app/Http/Controllers/Tenant/ContractController.php` | `showRevise` に `Request` 追加＋`$returnTo` 算出・受け渡し / `revise` の成功リダイレクトを `return_to` で分岐 |
| `resources/views/tenant/contracts/revise.blade.php` | パンくず・戻る・キャンセルの遷移先分岐 ＋ hidden `return_to` |
| `resources/views/tenant/units/show.blade.php` | 「賃料改定」ボタン追加（経営層のみ）＋「契約詳細を見る」リンク有効化 |
