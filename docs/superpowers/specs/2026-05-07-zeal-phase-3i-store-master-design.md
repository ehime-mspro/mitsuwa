# ZEAL Phase 3-I 設計書: 店舗マスタ管理 + 会員登録への店舗紐付け

作成日: 2026-05-07
ステータス: 着手前 / 設計レビュー待ち

## 背景

ZEAL Phase 3-A で `zeal_stores` テーブルと `zeal_members.store_id` (NOT NULL FK ON DELETE RESTRICT) を定義したが、Phase 3-A〜H の実装範囲では UI からの店舗管理機能は未実装だった。

本番デプロイ完了後 (2026-05-07) に、以下のギャップが判明:

1. `zeal_stores` テーブルに初期データが投入されていない
2. Store マスタ管理 UI が存在しない
3. 会員編集フォーム (`zeal/members/_form.blade.php`) に `store_id` フィールドが無い
4. CSV インポート (`Admin\ZealMemberImportController`) が `ZealMember::create()` で `store_id` を渡していない

このままでは:
- 会員 CSV 取込時に「Field 'store_id' doesn't have a default value」エラーで全行失敗する
- 仮に店舗を SQL 直接投入しても、UI からは店舗の編集・追加ができない
- 会員の所属店舗を後から変更する手段が無い

## 目的

ZEAL モジュールを実運用可能な状態に仕上げる。具体的には:

- 1店舗の初期データ投入
- 店舗マスタの管理 UI (Ajax CRUD)
- 会員フォームでの店舗選択
- CSV インポートでの店舗指定 (任意 + フォールバック)

## スコープ

このセッションで実施する範囲:

| # | 作業 | 種類 |
|---|---|---|
| 1 | `zeal_stores` 初期データ INSERT SQL 用意 | DB |
| 2 | Store マスタ管理画面 (Ajax CRUD) | 新規実装 |
| 3 | サイドバーに店舗マスタリンク追加 | UI 変更 |
| 4 | 会員編集フォームに「所属店舗」セレクト追加 | UI 変更 |
| 5 | `MemberController::update()` で `store_id` バリデーション追加 | Controller 変更 |
| 6 | CSV インポートに「所属店舗」列追加 (任意・フォールバック) | Controller 変更 |
| 7 | CSV テンプレートと取込画面ヘルプの更新 | UI 変更 |
| 8 | ローカル環境での動作確認 | 検証 |

スコープ外:
- 店舗マスタの show / create / edit ページ (1ページの Ajax CRUD で完結するため)
- CSV テンプレートに店舗列を必須化する運用 (将来複数店舗時に再検討)
- 既存会員データの店舗一括変更ツール (現時点で会員データなし)

## 初期データ

`zeal_stores` に投入する 1 件:

| 列 | 値 |
|---|---|
| name | ZEAL BOXING FITNESS 松山市駅前店 |
| address | 愛媛県松山市湊町6-2-2 ミツワ市駅西ビル2階 |
| phone | NULL |
| open_date | 2025-10-17 |
| display_order | 1 |
| active | 1 |

## アーキテクチャ

```
┌─────────────────────────────────┐
│ Store マスタ管理画面 (Ajax CRUD)        │ ← 新規追加
│  /zeal/stores                                │
│  Controller: Zeal\StoreController            │
│  View: zeal/stores/index.blade.php           │
└─────────┬────────────────────────────────────┘
          │ FK (zeal_members.store_id NOT NULL)
          ↓
┌─────────────────────────────────┐    ┌─────────────────────────────────┐
│ 会員編集フォーム                              │    │ CSV インポート                          │
│  /zeal/members/{id}/edit                     │    │  /admin/zeal/member-import              │
│  「所属店舗」select 追加 (必須)              │    │  「所属店舗」列追加 (任意・フォールバック) │
└─────────────────────────────────┘    └─────────────────────────────────┘
```

## コンポーネント

### 新規ファイル

| ファイル | 役割 |
|---|---|
| `app/Http/Controllers/Zeal/StoreController.php` | TrainerController と同構造 (index / store / update / destroy) |
| `resources/views/zeal/stores/index.blade.php` | trainers/index.blade.php を雛形に列構成を変更 |
| `database/sql/zeal_stores_seed.sql` | 初期 1件 INSERT 文 |

### 変更ファイル

| ファイル | 変更内容 |
|---|---|
| `routes/web.php` | `/zeal/stores` 4 ルート追加 (index/store/update/destroy) |
| `resources/views/layouts/partials/sidebar.blade.php` | 「店舗マスタ」リンク追加 (トレーナーマスタの直前) |
| `resources/views/zeal/members/_form.blade.php` | 「所属店舗」select 追加 (トレーナー select の左に配置) |
| `app/Http/Controllers/Zeal/MemberController.php` | `edit()` で `$stores` を渡す / `update()` で `store_id` を validate |
| `app/Http/Controllers/Admin/ZealMemberImportController.php` | 「所属店舗」列の読込・store_id マッピング・フォールバック |
| `resources/views/admin/zeal/member-import/index.blade.php` | テンプレート CSV 列に「所属店舗」追加・ヘルプ更新 |

## Store CRUD の振る舞い (Trainer 流用)

`Zeal\TrainerController` と同じパターンで実装する。

### index() - 一覧表示

```php
public function index()
{
    $stores = ZealStore::orderBy('display_order')->orderBy('id')->get();
    $storesJson = $stores->map(function ($s) {
        return [
            'id'            => $s->id,
            'name'          => $s->name,
            'address'       => $s->address,
            'phone'         => $s->phone,
            'open_date'     => $s->open_date?->format('Y-m-d'),
            'display_order' => $s->display_order,
            'active'        => (bool) $s->active,
        ];
    })->values();
    $nextOrder = ($stores->max('display_order') ?? 0) + 1;
    return view('zeal.stores.index', compact('storesJson', 'nextOrder'));
}
```

### store() - 新規追加 (Ajax)

バリデーション:
- `name` required string max:100
- `address` nullable string max:300
- `phone` nullable string max:20
- `open_date` nullable date
- `display_order` required integer min:0 max:9999
- `active` boolean

### update() - 更新 (Ajax)

同上のバリデーション。

### destroy() - 削除 (Ajax)

```php
if (ZealMember::where('store_id', $store->id)->exists()) {
    return response()->json([
        'success' => false,
        'message' => '「' . $store->name . '」には所属会員がいるため削除できません。「無効」に変更してご利用ください。',
    ], 422);
}
```

### Blade UI

- 一覧テーブル列: `店舗名 / 住所 / 電話 / 開店日 / 表示順 / 状態 / 操作`
- 住所列: `white-space: normal; word-break: break-word;` で折り返し
- 開店日: 案C datepicker パターンを使用 (`<input type="date">` 禁止 / 既存メモリ参照)
- 編集行: クリックで input 化 → 保存 / キャンセルボタン
- 新規追加: ヘッダーの「店舗を追加」ボタン → インライン展開フォーム

### Alpine.js 関数名

`zealStoreManager()` (script タグ内に定義 / アロー関数禁止 / `=>` 記号禁止)

## 会員編集フォームの変更

### `MemberController::edit()`

```php
$stores = ZealStore::where('active', 1)->orderBy('display_order')->orderBy('id')->get();
return view('zeal.members.edit', compact('member', 'plans', 'trainers', 'stores'));
```

### `MemberController::update()` バリデーション追加

```php
'store_id' => 'required|integer|exists:zeal_stores,id',
```

### `_form.blade.php` の追加

トレーナー select の左に配置 (同じ `grid-cols-2` 内):

```blade
<div>
    <label class="zeal-form-label" for="store_id">
        所属店舗<span style="color:#dc2626; font-size:11px; margin-left:4px; font-weight:700;">*必須</span>
    </label>
    <select id="store_id" name="store_id" class="form-input w-full" style="margin-bottom: 0;" required>
        @if($stores->isEmpty())
            <option value="">店舗マスタが未登録です</option>
        @else
            <option value="">選択してください</option>
            @foreach($stores as $store)
                <option value="{{ $store->id }}" {{ $valStore == $store->id ? 'selected' : '' }}>
                    {{ $store->name }}
                </option>
            @endforeach
        @endif
    </select>
</div>
```

`_form.blade.php` 上部 (Controller から渡された変数を `old()` で受ける部分):

```blade
$valStore = old('store_id', $member->store_id ?? '');
```

### Store マスタ未登録時の振る舞い

`$stores` が空の場合:
- select は無効状態 (option に「店舗マスタが未登録です」を表示)
- 保存ボタン直前に警告メッセージ: 「店舗マスタを先に登録してください。」

## CSV インポートの変更 (案Z: 列追加・任意・フォールバック)

### テンプレート CSV の列に「所属店舗」を追加

`ZealMemberImportController::template()` または該当部分にて、CSV の最後尾に「所属店舗」列を追加。

### 読込ロジック (`preview()` / `execute()`)

```php
// 有効店舗マップを作成
$storeMap = ZealStore::where('active', 1)
    ->pluck('id', 'name')
    ->toArray();
$defaultStore = ZealStore::where('active', 1)
    ->orderBy('display_order')
    ->orderBy('id')
    ->first();

// 有効店舗が 1 件もない場合はインポート全体をエラー
if (!$defaultStore) {
    return redirect()->back()->with('error', '有効な店舗が登録されていません。先に店舗マスタを登録してください。');
}

// 各行で:
$storeName = $row['store_name'] ?? '';  // CSV の値
if ($storeName === '') {
    $storeId = $defaultStore->id;        // フォールバック
} elseif (isset($storeMap[$storeName])) {
    $storeId = $storeMap[$storeName];
} else {
    $rowErrors[] = "所属店舗「{$storeName}」が見つかりません";
    continue;
}

// ZealMember::create([... 'store_id' => $storeId, ...])
```

### プレビュー画面の表示

- フォールバックされた行: 「所属店舗 (既定値適用)」と注記
- マッピング失敗の行: 赤背景でエラー表示

### 取込画面ヘルプ更新

`resources/views/admin/zeal/member-import/index.blade.php` に注記を追加:

> 「所属店舗」列は任意です。空欄の場合は表示順が最も小さい有効店舗 (現在: ZEAL BOXING FITNESS 松山市駅前店) に自動で紐付きます。

## エラーハンドリング

| ケース | 振る舞い |
|---|---|
| Store マスタ未登録で会員編集を開く | select 無効化 + 警告メッセージ表示 |
| Store マスタ未登録で CSV インポート実行 | プレビュー前に全体エラー停止 |
| 会員所属の Store を削除 | 422 + メッセージ「無効化してください」 |
| CSV の「所属店舗」が存在しない店舗名 | 行エラー (赤背景) |
| `store_id` が `exists:zeal_stores,id` を満たさない | バリデーションエラー |

## CSS / Blade ルール準拠

- `<input type="date">` 禁止 → 開店日も datepicker パターン使用 (memory: project_mansion_datepicker_pattern)
- アロー関数 `=>` を `x-data` 内で使わない (CLAUDE.md)
- `style=` と `:style=` の同居禁止 (CLAUDE.md)
- 単一行 `@if/@else/@endif` 禁止 (CLAUDE.md)
- `@json()` 内で関数呼び出ししない (CLAUDE.md)
- 新規 Tailwind クラスは追加せず、既存 utility のみ使用 (Vite ビルド済み CSS)
- 必要なスタイルは `<style>` ブロックまたはインラインで記述

## ルート定義

`routes/web.php` の ZEAL ブロック内に追加 (Trainer ルートと同じ位置):

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

## サイドバー

ZEAL グループ内、トレーナーマスタの直前に「店舗マスタ」リンクを追加。
3 パターン (executive / manager / staff) すべてに反映 (Trainer マスタと同条件: manager 以上に表示)。

## テスト戦略 (動作確認)

ローカル環境 (`https://localhost/manage/public`) で順に確認:

| # | 操作 | 期待結果 |
|---|---|---|
| 1 | `database/sql/zeal_stores_seed.sql` を phpMyAdmin で実行 | 1 件挿入される |
| 2 | `/zeal/stores` にアクセス | 1 件表示される |
| 3 | 「店舗を追加」で 2 店舗目を追加 | 一覧に反映 |
| 4 | 行をクリックして編集 → 保存 | 一覧に反映 |
| 5 | 削除を試行 (会員紐付けなし) | 削除成功 |
| 6 | テスト会員を SQL で 1 件作成 | (会員データ準備) |
| 7 | `/zeal/members/{id}/edit` を開く | 「所属店舗」select に 1 件表示 |
| 8 | 店舗を変更して保存 | DB の `store_id` 更新確認 |
| 9 | 紐付き会員のいる店舗を削除試行 | 422 エラー + 「無効化してください」表示 |
| 10 | テスト用 CSV (店舗列なし) でプレビュー | 全行が既定店舗にフォールバック |
| 11 | テスト用 CSV (店舗列あり・有効値) でプレビュー | 指定店舗にマッピング |
| 12 | テスト用 CSV (店舗列あり・存在しない値) でプレビュー | エラー行として赤表示 |

## デプロイ手順 (本番反映時)

1. 設計書とコードをコミット
2. `database/sql/zeal_stores_seed.sql` の中身を本番 phpMyAdmin で手動実行
3. `deploy.sh` でファイル反映
4. 本番 `/zeal/stores` で表示確認
5. 本番 `/zeal/members/{any}/edit` で店舗 select 確認 (会員データはまだない想定)

## 受け入れ基準

- `/zeal/stores` で店舗の追加・編集・削除が UI から可能
- 会員編集フォームで「所属店舗」が必須項目として機能
- CSV インポートで `store_id` が正しく入る (フォールバック / 明示指定の両方で動作)
- 既存の Trainer / Plan / Member 機能に影響なし
