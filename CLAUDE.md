# CLAUDE.md — ミツワ都市開発 経営管理システム

## プロジェクト概要

ミツワ都市開発の社内業務管理システム。テナント管理・不動産事業・住宅事業を横断する経営管理プラットフォーム。

- **システム名:** ミツワ都市開発 経営管理システム（manage）
- **URL:** `https://domain/manage/public/`
- **技術スタック:** Laravel 12.x / PHP 8.5.4 / MySQL 8.0
- **フロントエンド:** Blade + Tailwind CSS v4（Viteビルド） + Alpine.js v3
- **サーバー:** macOS（PHP CLIなし）
- **現在のルート数:** 約185

## 開発ワークフロー

1. **要件定義** — 構造化Q&Aで全仕様を確定
2. **デザインモック** — HTMLモックをレビュー・承認後に実装開始
3. **実装** — 全ファイルセットを一括納品
4. **品質監査** — 30項目チェック（本ファイルのルール準拠確認）
5. **デプロイ** — 政則がファイル配置・SQL実行・キャッシュクリア
6. **セッション引継ぎ** — ZIP + `HANDOFF_PROMPT.md` で次セッションに継続

## サーバー環境

- PHP CLIなし。マイグレーションは `sudo mysql manage < file.sql` で直接実行
- キャッシュクリア: `sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2`
- macOS: `sed -i ''` 形式（GNU形式は不可）
- phpMyAdmin利用可

## 絶対厳守ルール

### CSS（★最重要）

- **Viteビルド方式。CDNではない。** `build/assets/app-xxxx.css` を読み込む
- 新規Bladeでは**既存ファイルで使用実績のあるクラスのみ有効**。新しいクラスはCSSに含まれず効かない
- 使用実績のないスタイリングは**全てインラインスタイルまたは`<style>`ブロック**で対応
- Viteビルド未収録の例: `gap-5`, `md:grid-cols-2`, `mt-auto`, `py-0.5`, `pb-2.5`, `items-end`, `border-red-600`
- `pl-9`, `pl-10` 等が効かない場合がある → インラインスタイル使用
- `border-l-4 border-emerald-500` が効かない場合がある → `style="border-left: 4px solid #10b981;"`
- カスタム `shadow-[]` は動作しない → インラインスタイル使用

### Alpine.js

- **`x-data` 属性内に `>` を含むJS（アロー関数 `=>` 等）は絶対禁止。** ブラウザが `>` をHTML閉じタグと解釈し、JSがテキストとして画面に表示される
- `<script>` タグ内の名前付き関数に分離して `x-data="functionName()"` で呼び出す
- `<script>` タグ内でもアロー関数 `=>` は使わず `function()` 構文を使用
- `<template x-if>` は `x-for` 内やSVG内で不安定 → `x-show` を使用
- **`style` 属性と `:style` 属性の併用禁止。** Alpine が `:style` で上書きすると静的 `style` が全て消失する → 全スタイルを `:style` 1つに統合

### Blade

- `@if/@else/@endif` は**必ず複数行形式**で記述。`@else` 直後に `<` や英数字が続くとLaravel 12のBladeコンパイラがエラー
- `@json()` 内で `fn()`, `number_format()`, collection メソッド等のPHP関数は**禁止**。Controller側でデータを事前整形して渡す
- `<x-attachment-section>` は使わない → `@include('components.attachment-section', [...])` で呼び出す

### フォーム

- 顧客フォームと同じクラス構成を踏襲: `form-input`, `gap-3`, `grid grid-cols-1 sm:grid-cols-2`
- フォーム項目間隔: `margin-bottom: 26px`
- `form-input` クラスに `border-radius` が含まれていない場合がある → `<style>` ブロックでデザインモック準拠のCSSを定義
- 同一フォーム内で同じ `name` 属性を持つ input が複数存在すると、`x-show` で非表示にしても全てsubmitされ、最後の値で上書きされる → hidden input + Alpine変数バインド、または `:disabled` で排他制御

### 金額・表示

- 金額は税抜表示
- 円後置表記: `28,500,000円`（`¥` プレフィックスは使用しない）
- 粗利額の表示色: `color: #047857; font-weight: 700;`
- バッジはViteビルドに含まれないため**インラインスタイル**で実装（`badgeStyle()` メソッド）
- 建ぺい率・容積率は整数表示（小数点不要）
- 金額入力フィールドにデフォルト値「0」を入れない

### テーブル・一覧

- フィルター: 全一覧画面統一。セレクト `onchange="document.getElementById('filter-form').submit()"` で即時絞り込み
- クリアボタン: `h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400` の控えめスタイル
- 金額列ヘッダー: `text-align: center`、数値セルは右寄せ
- 担当者表示: 苗字のみ（同姓の場合のみフルネーム）
- ページネーション: 20件/ページ

### データ・ロジック

- 決算年度: **5月始まり**（5月〜翌年4月）
- 費用並び順: 家賃→共益費→ゴミ代→駆除代→敷金
- 坪単価: `ceil(金額/坪数)` を `(@金額)` 表示
- 月額合計: 家賃+共益費+ゴミ代+駆除代（敷金含まない）
- 税込計算: `(int) round($total * 1.1)`
- 都道府県デフォルト: 新規登録時は「愛媛県」
- `re_projects` テーブルのカラム名は `project_name`（`name` ではない）

### Laravel固有

- `defaults()` は使わない。URLセグメントから `request()->segment(1)` で判定する `resolveDepartment()` メソッドを使用
- `User` モデルに `deleted_at` カラムの有無が不明 → `User::whereNull('deleted_at')` は使わず `User::orderBy('name')` のみ使用
- `Buyer` モデルは `SoftDeletes` 使用 → リレーションには `->withTrashed()` を付ける
- `reorder` ルートは `{model}` パラメータルートより前に配置
- ソフトデリートされた関連データは `->withTrashed()` で取得

### Chart.js

- CDNは `cdn.jsdelivr.net` を使用（`cdnjs.cloudflare.com` はブロックされる）
- `onload` コールバックパターンで読み込み。IIFEは使わない

### 郵便番号⇔住所変換

- 正引き（〒→住所）: zipcloud API（フロントJSから直接）
- 逆引き（住所→〒）: HeartRails GeoAPI `getTowns`（サーバーサイドcURL）

## ディレクトリ構成

```
manage/
├── app/
│   ├── Enums/           # ProcurementStatus, ProjectStatus, LotStatus, ReContractType, ReContractStatus, etc.
│   ├── Http/Controllers/
│   │   ├── Admin/       # UserController, UsageTypeController, ReCostItemController, SurveyQuestionController, CustomerImportController
│   │   ├── Housing/     # PropertyController, ContractController, CustomOrderController
│   │   ├── RealEstate/  # ProcurementController, ProjectController, SupplierController, ReContractController
│   │   └── Tenant/      # PropertyController, ContractController, CustomerController, InvestmentController, RepairController, InquiryController
│   └── Models/          # ReContract, ReProcurement, ReProject, ReProjectLot, Buyer, etc.
├── resources/views/
│   ├── layouts/partials/ # sidebar.blade.php
│   ├── realestate/       # procurements/, projects/, suppliers/, contracts/
│   ├── housing/          # properties/, custom-orders/, contracts/
│   ├── buyers/           # 買主マスタ（共有）
│   └── tenant/           # properties/, contracts/, customers/, investments/, repairs/, inquiries/
├── routes/
│   └── web.php           # 全ルート定義（buyer_routes.php, housing_routes.php 等をインクルード）
└── database/sql/         # 直接実行用SQL
```

## 完了済みモジュール

| モジュール | ルート数 | 備考 |
|-----------|---------|------|
| STEP 1〜11 テナント管理基盤〜ファイル添付 | 多数 | 物件/契約/投資/修繕/問合せ/顧客/収支 |
| 不動産 仕入れ管理 | 23 | Google Maps連携、原価管理Ajax |
| 不動産 分譲地PJ | 16 | 区画管理、図面管理、収支シミュレーション |
| 不動産 仕入れ先管理 | 7 | ソフトデリート対応 |
| 住宅事業 建売管理 | 16 | 契約管理含む |
| 住宅事業 注文住宅管理 | 10 | ファイルカテゴリ管理 |
| 顧客管理（買主マスタ） | 約29 | 部署横断共有、アンケート、CSVインポート |
| フィルター統一 | — | 全9一覧画面 |
| 不動産 契約管理 | 12 | 5種別統合、仲介ライフサイクル |

## 次の開発候補

| 優先 | 内容 | 状態 |
|------|------|------|
| 1 | 契約管理（住宅事業） | 不動産完了。着手可能 |
| 2 | 住宅事業横断一覧 | 建売・注文・リフォーム完成後 |
| 3 | リフォーム工事管理 | 後日 |
| 4 | STEP 12 ダッシュボード | 経営/テナント2種類 |

## 過去に発生した技術的問題と教訓

| 問題 | 原因 | 対策 |
|------|------|------|
| `x-data` 内のJSがHTMLとして表示される | `=>` の `>` がHTML閉じタグ解釈 | scriptタグに分離 |
| `style` と `:style` 併用でスタイル消失 | Alpineが `:style` で全上書き | `:style` 1つに統合 |
| `name` 属性重複でフォームデータ消失 | `x-show` は非表示でもsubmitされる | hidden + Alpine変数 or `:disabled` |
| redirectでフラグメントが効かない | route() にパラメータとして渡した | `redirect(route(...) . '#fragment')` |
| バリデーション後にチェックボックス復元されない | `old()` が文字列配列 | `.map(Number)` 追加 |
| `@json()` 内の `fn()` | Blade制約 | Controller事前整形 |
| CSS方式がCDNではなくViteビルド | 認識誤り | 既存クラスのみ使用 |
| `re_projects.name` が存在しない | カラム名は `project_name` | 全箇所修正 |
| `defaults()` でController引数に注入されない | Laravel 12ではURLに `{param}` がないと注入されない | `resolveDepartment()` |
| `User::whereNull('deleted_at')` でSQLエラー | `users` テーブルに `deleted_at` がない | `whereNull` を除去 |
| フリガナ自動入力で漢字が入る | `compositionupdate` の途中で漢字化 | `compositionend` でカタカナ変換 |
| ソフトデリート済み買主がドロップダウンに出ない | `Buyer` の `SoftDeletes` | `->withTrashed()` + 編集時に現在の買主を含める |
| 金額入力に初期値「0」 | placeholder/Alpine初期値 | 空文字に変更 |
| 建ぺい率が `80.00%` と表示 | Model cast `decimal:2` | `integer` に変更 |
