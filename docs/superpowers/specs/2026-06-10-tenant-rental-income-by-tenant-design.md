# テナント賃料収入履歴 — 契約（テナント）別集計への変更 設計書

- 作成日: 2026-06-10
- 対象: テナント管理 — 区画詳細「収支履歴」タブ／物件詳細「収支」タブ
- ステータス: 設計承認済み
- 前提: STEP 7（月次集計版・commit `4e46ffc2` 本番稼働）を**契約別集計に作り替える**変更
- ブランチ: `step7-rental-income-by-tenant`（HEAD: 4e46ffc2 から分岐）

## 1. 背景・目的

STEP 7 で実装した「収支履歴」タブは、上部カード2枚（累計賃料収入・現在の月額）＋**月次の計上年月一覧**で構成されている。月次一覧は古い契約（例: 2009 年契約）で約 200 行に達し冗長。

本変更では月次一覧を **契約（テナント入居）別の賃料収入合計** に置き換える。1 契約 = 1 行とし、店舗ごとの入居期間と累計賃料収入を一覧する。カード2枚は維持。

## 2. スコープ

### 対象
- 区画詳細（`tenant/units/show.blade.php`）「収支履歴」タブ
- 物件詳細（`tenant/properties/show.blade.php`）「収支」タブ
- 共通 partial `tenant/partials/_rental-income.blade.php` の月次表を契約別表に置換
- `App\Services\Tenant\RentalIncomeService::build()` の戻り値 `rows` を月次 → 契約別に変更
- ユニットテストの更新

### 非対象（YAGNI）
- カード2枚（累計賃料収入・現在の月額）の構成・算出（**現状維持**）
- 支出・`transactions` テーブル・賃料改定の反映（STEP 7 の非対象を踏襲）
- DB スキーマ変更・新規ルート

## 3. データ制約（実データ調査 2026-06-10）

| 事実 | 値 | 設計への影響 |
|---|---|---|
| `contracts` 総数 | 10（全 active、terminated 0） | 「以前契約B」は将来用。設計では terminated も扱うが実データは現契約のみ |
| `customer_id` null | 10件中 **9件** | 「契約者」列は省略（顧客マスタ名がほぼ無い）。**店舗名主体** |
| `store_name` | **全件あり** | 行の主見出しは `store_name` |
| `rent_start_date` null | **全件** | 家賃発生起点は `rent_start_date ?? contract_date`（現行ロジック踏襲）。実質 `contract_date` 起点 |
| `contract_date` | 全件あり | 起点として機能（例: 2009-06-17, 2017-05-08, 2023-03-16） |

## 4. 表示仕様

### 全体構成（区画・物件 共通 partial）
- 上部カード2枚（累計賃料収入・現在の月額）: **変更なし**
- その下: 契約別集計表

### 表の列

| 列 | 内容 |
|---|---|
| ステータス | 「現契約」(active)／「以前契約」(terminated) のラベル + `badgeClass()` の色バッジ（バッジ色は既存 `badge-occupied`/`badge-terminated` 流用） |
| 店舗名 | `store_name`（null は「—」） |
| 期間 | 家賃発生（`rent_start_date ?? contract_date`）〜終端。現契約=「現在」、以前契約=`contract_end_date`。`2023-03〜現在` 形式 |
| 賃料収入 | その契約の**累計**（家賃発生〜終端の月次展開合計、初月/最終月調整込み）。`14,960,000円` 形式 |

### 行の単位・並び順・スコープ
- 1 行 = 1 契約（= 1 テナント入居期間）。同区画でテナント交代があれば現契約・以前契約が別行
- 並び順: **現契約（active）→ 以前契約（terminated）**、各グループ内は家賃発生月の降順（新しい順）
- 区画詳細: その区画の契約（現＋過去）/ 物件詳細: 物件配下の全契約
- 空状態: 「賃料収入の履歴がありません。」

### スタイル
- 既存「scroll-hint」テーブル構造を踏襲。Tailwind はビルド済みクラス＋ inline style（任意値クラス禁止・Bug #19）
- ステータスバッジは既存の `badge` + `$contract->status->badgeClass()`（区画詳細「現在の契約」等で使用実績あり）

## 5. データ層（RentalIncomeService）

`build(Collection $contracts): array` の戻り値を変更。`forUnit`/`forProperty`/`expandContractMonths` は不変（再利用）。

### 変更後の戻り値

```php
[
  'rows' => [
    [
      'store_name'   => 'Lancelot',
      'status'       => 'active',          // ContractStatus value
      'status_label' => '現契約',          // active='現契約' / terminated='以前契約'
      'badge_class'  => 'badge-occupied',  // $contract->status->badgeClass()
      'period_label' => '2023-03〜現在',
      'income'       => 14960000,          // 累計（expandContractMonths の合計）
      'sort_active'  => 1,                 // active=1/terminated=0（並び替え用）
      'sort_ym'      => '2023-03',         // 家賃発生月（並び替え用）
    ],
    // ... 並び順適用済み
  ],
  'total_income'    => 14960000,  // 全契約 income の合計（カード用・現状維持）
  'current_monthly' => 374000,    // active 契約の monthly_total 合計（カード用・現状維持）
]
```

### 構築ロジック

```php
public function build(Collection $contracts): array
{
    $rows = [];
    foreach ($contracts as $contract) {
        $income = array_sum($this->expandContractMonths($contract));
        $start  = $contract->rent_start_date ?? $contract->contract_date;
        $isActive = $contract->status === ContractStatus::Active;

        // 期間ラベル
        $startYm = $start?->format('Y-m') ?? '—';
        $endYm = $isActive
            ? '現在'
            : ($contract->contract_end_date?->format('Y-m') ?? '—');

        $rows[] = [
            'store_name'   => $contract->store_name,
            'status'       => $contract->status->value,
            'status_label' => $isActive ? '現契約' : '以前契約',
            'badge_class'  => $contract->status->badgeClass(),
            'period_label' => "{$startYm}〜{$endYm}",
            'income'       => $income,
            'sort_active'  => $isActive ? 1 : 0,
            'sort_ym'      => $start?->format('Y-m') ?? '0000-00',
        ];
    }

    // 並び順: active 先、各内 家賃発生月 降順
    usort($rows, function ($a, $b) {
        return [$b['sort_active'], $b['sort_ym']] <=> [$a['sort_active'], $a['sort_ym']];
    });

    $totalIncome = array_sum(array_column($rows, 'income'));
    $currentMonthly = $contracts
        ->filter(fn (Contract $c) => $c->status === ContractStatus::Active)
        ->sum(fn (Contract $c) => $c->monthly_total);

    return [
        'rows'            => $rows,
        'total_income'    => (int) $totalIncome,
        'current_monthly' => (int) $currentMonthly,
    ];
}
```

- `expandContractMonths` は STEP 7 のまま（開始 = `rent_start_date ?? contract_date`、終了 = `min(contract_end_date ?? 当月, 当月)`、初月/最終月調整、未来非計上）。
- `total_income` は「全契約 income の合計」= 月次版の総累計と一致（カード値は不変）。

## 6. 表示（partial）

```blade
@php $rows = $rentalIncome['rows'] ?? []; @endphp

{{-- カード2枚: 現状維持 --}}
... (累計賃料収入 / 現在の月額) ...

@if(!empty($rows))
  <div class="scroll-hint at-start">
    <div class="scroll-hint-inner">
      <table class="w-full border-collapse text-sm" style="min-width:480px">
        <thead><tr>
          <th>ステータス</th><th>店舗名</th><th>期間</th><th class=text-right>賃料収入</th>
        </tr></thead>
        <tbody>
          @foreach($rows as $row)
            <tr>
              <td><span class="badge {{ $row['badge_class'] }}">{{ $row['status_label'] }}</span></td>
              <td>{{ $row['store_name'] ?? '—' }}</td>
              <td>{{ $row['period_label'] }}</td>
              <td class=text-right>{{ number_format($row['income']) }}円</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@else
  <p>賃料収入の履歴がありません。</p>
@endif
```

（実装時は STEP 7 partial の確定済みクラスを踏襲。`badge` + `badgeClass()` は既存スタイル）

## 7. テスト（更新）

`tests/Unit/Tenant/RentalIncomeServiceTest.php` を契約別 `rows` 構造に合わせて更新:

- 契約なし → `rows` 空・`total_income=0`・`current_monthly=0`
- active 契約1 → `rows` 1件、`income`=家賃発生〜当月の累計、`status='active'`、`period_label` 末尾「〜現在」
- terminated 契約1 → `rows` 1件、`income`=家賃発生〜解約月の累計、`period_label` が `YYYY-MM〜YYYY-MM`
- active + terminated（テナント交代）→ `rows` 2件、**active が先頭**、各 `income` が個別累計
- フリーレント（initial_month_type=free, amount=0）→ 当該契約の `income` に 0 月が反映
- `total_income` = 全 `rows` の `income` 合計、`current_monthly` = active のみ monthly_total 合計

実行: worktree 静的検証（`php -l`）→ main repo マージ後に `composer install`（dev込み）→ `vendor/bin/phpunit --filter=RentalIncomeServiceTest` → `composer install --no-dev`。

## 8. 影響範囲・リスク

- 変更は `RentalIncomeService::build()`（戻り値構造）と partial（表）に限定。`forUnit`/`forProperty`/`expandContractMonths`・コントローラ・ルートは不変
- カード2枚（`total_income`/`current_monthly`）は値・表示とも不変
- `customer` 不使用化により N+1 やリレーション例外の懸念が減る（`store_name` はカラム直読み）
- 本番 `view:cache` で Blade precompile 確認（Bug #21）。バッジは既存 `badgeClass()` 利用で Bug #22 系の `tryFrom` 誤用なし

## 9. デプロイ手順

STEP 7 と同一: worktree 実装・`php -l` → `/commit` → main repo で FF-merge → `composer dump-autoload` → テスト（`composer install`→`vendor/bin/phpunit`→`composer install --no-dev`）→ `./deploy.sh` → 本番スモーク。
