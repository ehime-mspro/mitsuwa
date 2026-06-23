# 募集家賃改定フォーム 坪数表示・坪単価計算 設計書

- 作成日: 2026-06-23
- 対象: テナント区画「募集家賃の改定」画面（`GET /tenant/units/{unit}/revise`）の入力体験を改善する。
- 種別: ビュー改修のみ（1ファイル: `resources/views/tenant/units/revise.blade.php`）。**Controller / Model / migration / ルート / DBスキーマ いずれも不変**。
- 関連: [2026-06-22-tenant-unit-asking-rent-revision-design.md](2026-06-22-tenant-unit-asking-rent-revision-design.md)（募集家賃改定フォーム本体）の後続。
- モック（見た目・動作の正解・検証済み）: `docs/mockups/tenant/units/revise.html`

## 1. 背景・目的

募集家賃の改定入力を、(1) 区画の坪数を確認しながら、(2) 適用日を毎回入力する手間を省き、(3) 「坪単価 × 坪数 = 金額」で坪単価ベースの金額決定をできるようにして、商業区画の家賃設定実務に合わせる。

## 2. 変更点（3件）

### 2.1 対象区画カードに「坪数」を表示
- 既存「対象区画」カード（`grid grid-cols-2 lg:grid-cols-3`）の3セル目に追加。
- 表示: `number_format((float)$unit->area_tsubo, 2) . '坪'`（例 `7.50坪`）。坪数が `null`/`0` のときは `—`。

### 2.2 改定適用日の初期値を本日に
- `value="{{ old('revision_date') }}"` → `value="{{ old('revision_date', now()->format('Y-m-d')) }}"`。
- 入力は従来どおりネイティブ `<input type="date">`（カレンダーから変更可）。専用ピッカーは導入しない。

### 2.3 「新・募集家賃」「新・共益費」に “坪単価→金額” 計算欄を新設（この2項目のみ）
- レイアウト: `[坪単価(円/坪)] × 〇.〇〇坪 = [金額(円)]` を緑枠（`bg #f0fdf4` / `border #bbf7d0`）で囲む。
- 双方向同期（端数は四捨五入 `Math.round`）:
  - 坪単価入力 → `金額 = round(坪単価 × 坪数)`
  - 金額入力 → `坪単価 = round(金額 ÷ 坪数)`
- 初期表示: 金額に現在値（`new_rent=$unit->rent` / `new_common_fee=$unit->common_fee`、`old()` 優先）、坪単価は金額÷坪数で逆算表示。
- **送信・保存される値は金額のみ**（`name=new_rent` / `name=new_common_fee`）。坪単価入力は `name` を持たず非保存（計算補助）。
- 坪数未設定（`null`/`0`）のとき: 坪単価欄を `disabled` ＋グレーアウト（CSS `.calc-input:disabled`）し「坪数未設定」と注記。金額は手動入力可。
- 新・ゴミ代 / 新・駆除代は金額のみ（坪単価計算なし＝従来どおり）。見た目は同フォーム内で `.calc-input` に統一。

## 3. 実装詳細（Blade 1ファイル）

- `@php` で `$areaTsubo = (float)$unit->area_tsubo; $hasArea = $areaTsubo > 0; $initRent = (int) old('new_rent', $unit->rent); $initFee = (int) old('new_common_fee', $unit->common_fee);` を用意（`(int)`/`(float)` で常に妥当な数値リテラル化）。
- Alpine: `<form>` に `x-data="reviseForm({{ $areaTsubo }}, {{ $initRent }}, {{ $initFee }})" x-init="init()"`。関数は別 `<script>`（Bug #1）。`@json` は使わず数値リテラルのみ渡す（Bug #23 回避）。
- 同期: 坪単価 input に `x-model.number="rentUnitPrice" @input="newRent = toAmount(rentUnitPrice)"`、金額 input に `x-model.number="newRent" @input="rentUnitPrice = toUnitPrice(newRent)"`。共益費も同型（`feeUnitPrice`/`newFee`）。
- 無効化: 坪単価 input に `@disabled(!$hasArea)`。グレーは `<style>` の `.calc-input:disabled` で付与（`:style` を使わない＝Bug #2 回避）。`toUnitPrice` は坪数なし時 `''` を返し空表示。
- レイアウトは inline style で構築（`items-end`/`gap-5` 等の未コンパイル Tailwind を避ける＝Bug #19）。`<style>`/`<script>` は `@section('content')` 内にインライン（レイアウトに `@stack` なし）。
- 全角→半角変換は `layouts/app.blade.php` のグローバルリスナが `input[type=number]`/`[inputmode=numeric]` に自動適用。坪単価・金額 input は `type="number" inputmode="numeric"`（整数円）。

### Alpine 関数（別 `<script>`）
```js
function reviseForm(area, initRent, initFee) {
    return {
        areaTsubo: area || 0,
        newRent: initRent || 0,
        newFee: initFee || 0,
        rentUnitPrice: '',
        feeUnitPrice: '',
        init() {
            if (this.hasArea()) {
                this.rentUnitPrice = this.toUnitPrice(this.newRent);
                this.feeUnitPrice = this.toUnitPrice(this.newFee);
            }
        },
        hasArea() { return this.areaTsubo > 0; },
        toAmount(u)  { return this.hasArea() ? Math.round((u || 0) * this.areaTsubo) : 0; },
        toUnitPrice(a) { return this.hasArea() ? Math.round((a || 0) / this.areaTsubo) : ''; },
    };
}
```

## 4. 非対象（YAGNI）
- Controller の validation 変更（`new_rent`/`new_common_fee` 等は現行のまま。坪単価は無関係）。
- 坪単価の保存・履歴化（`unit_rent_revisions` 列追加なし）。
- ゴミ代/駆除代の坪単価計算。日付の専用ピッカー。

## 5. 検証（Bug #26 対策）
- `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`（コンパイル済みビューを `php -l`。`view:cache` 成功表示だけでは不十分）。
- モック（`docs/mockups/tenant/units/revise.html`）で見た目・双方向同期・端数四捨五入・坪数未設定時の挙動を再確認。

## 6. 本番反映
- main repo の `13.x` で実装 → `/commit` → `./deploy.sh`（view:cache 再生成）。新規 PHP クラスなし → `composer dump-autoload` 不要。ルート変更なし。`@json` 追加なし＝Bug #26 該当なし。push は明示指示時のみ。
