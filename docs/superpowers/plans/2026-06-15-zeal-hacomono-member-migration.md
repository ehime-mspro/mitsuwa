# ZEAL 会員 移行インポート（hacomono形式CSV）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** hacomono形式（77列）のエクスポートCSVから ZEAL 会員35件を `zeal_members` / `zeal_member_contracts` へ移行する専用 artisan コマンドを新設する。

**Architecture:** 純粋ロジック（`HacomonoMemberMapper`：CSV行→マッピング結果DTO）＋ CSV読込（`HacomonoCsvReader`）＋ 薄いコマンド（`ZealImportMembersCommand`：dry-run/commit）の3層。Mapper と Reader は DB 非依存でユニットテストする。既存 `ZealMemberImportController` は変更しない。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）/ PHPUnit。Eloquent（`ZealMember`/`ZealMemberContract`/`ZealPlan`/`ZealStore`）、`App\Support\Settings::taxRate()`。

**設計書:** `docs/superpowers/specs/2026-06-15-zeal-hacomono-member-migration-design.md`

---

## 作業ブランチ・テスト実行の前提（重要）

- **TDD のため main repo（`/Users/masanori/site/manage`）で作業する。** worktree には vendor が無く phpunit を実行できない（既知の制約）。main repo は vendor あり。
- 作業前に dev 依存を入れる: `composer install`（テスト後に `composer install --no-dev` で戻す）。
- 13.x から feature ブランチを切る: `git checkout -b feature/zeal-hacomono-import`。完了後に 13.x へ `--ff-only` マージ → `composer dump-autoload`（main repo）→ `./deploy.sh`。
- テスト実行コマンド: `vendor/bin/phpunit --filter <Class>` または `vendor/bin/phpunit tests/Unit/Zeal`。
- 各タスクは feature ブランチ上で逐次コミット。

## File Structure

| ファイル | 責務 |
|---|---|
| `app/Support/Zeal/MappedMember.php`（新規） | マッピング結果のDTO（会員属性 / 契約属性 / 区分 / 表示用フィールド / 警告・エラー） |
| `app/Support/Zeal/HacomonoMemberMapper.php`（新規） | 純粋ロジック。CSV行(array)→`MappedMember`。プラン解決・税抜換算・区分判定・メモ生成。DB非依存 |
| `app/Support/Zeal/HacomonoCsvReader.php`（新規） | 文字コード判定・BOM除去・引用内改行対応で CSV を連想配列の配列に変換。DB非依存 |
| `app/Console/Commands/ZealImportMembersCommand.php`（新規） | CSV読込→マスタ取得→Mapper適用→プレビュー出力（dry-run）/ トランザクション投入（commit） |
| `tests/Unit/Zeal/HacomonoMemberMapperTest.php`（新規） | Mapper のユニットテスト |
| `tests/Unit/Zeal/HacomonoCsvReaderTest.php`（新規） | Reader のユニットテスト |
| `tests/fixtures/zeal/reader_sample.csv`（新規） | Reader テスト用の小さなCSV（BOM＋引用内改行） |

**コマンドの永続化（DB書込）部分は phpunit ではテストしない**理由: ZEAL テーブルは Laravel migration ではなく `database/sql/*.sql` 直接管理で、`RefreshDatabase` が使えない。代わりに **ローカル実DB でのリハーサル（dry-run→commit→UI確認→truncate）** で検証する（§ロールアウト）。純粋部分（Mapper/Reader）は phpunit で厚くテストする。

---

## Task 1: MappedMember DTO と Mapper スケルトン（isInScope）

**Files:**
- Create: `app/Support/Zeal/MappedMember.php`
- Create: `app/Support/Zeal/HacomonoMemberMapper.php`
- Test: `tests/Unit/Zeal/HacomonoMemberMapperTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Zeal;

use App\Support\Zeal\HacomonoMemberMapper;
use PHPUnit\Framework\TestCase;

class HacomonoMemberMapperTest extends TestCase
{
    private function mapper(): HacomonoMemberMapper
    {
        // name => id / name => price(excl) / store name => id
        $planId = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 1,
            'パーソナル&セミパーソナル通い放題（1枠）' => 2,
            'パーソナル&セミパーソナル月4回' => 3,
            'セミパーソナル通い放題' => 4,
            'ペアプラン' => 5,
        ];
        $planPrice = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 24000,
            'パーソナル&セミパーソナル通い放題（1枠）' => 18000,
            'パーソナル&セミパーソナル月4回' => 13000,
            'セミパーソナル通い放題' => 9800,
            'ペアプラン' => 20700,
        ];
        $storeId = ['松山市駅前店' => 1];
        return new HacomonoMemberMapper($planId, $planPrice, $storeId, 1, 10.0);
    }

    public function test_in_scope_includes_member_and_suspended_only(): void
    {
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '会員']));
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '停止中']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => 'ビジター']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => '']));
    }
}
```

- [ ] **Step 2: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: FAIL（`Class "App\Support\Zeal\HacomonoMemberMapper" not found`）

- [ ] **Step 3: DTO とスケルトンを実装**

`app/Support/Zeal/MappedMember.php`:
```php
<?php

namespace App\Support\Zeal;

class MappedMember
{
    /**
     * @param array<string,mixed> $memberAttributes
     * @param array<string,mixed>|null $contractAttributes
     * @param string[] $warnings
     * @param string[] $errors
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $displayName,
        public readonly string $status,
        public readonly string $kind,
        public readonly ?string $planName,
        public readonly string $rawPlan,
        public readonly ?int $sourceAmountIncl,
        public readonly ?int $courseListIncl,
        public readonly ?int $appliedPriceExcl,
        public readonly ?string $withdrewOn,
        public readonly ?string $scheduledOn,
        public readonly array $memberAttributes,
        public readonly ?array $contractAttributes,
        public readonly array $warnings,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`app/Support/Zeal/HacomonoMemberMapper.php`:
```php
<?php

namespace App\Support\Zeal;

class HacomonoMemberMapper
{
    public const KIND_ACTIVE        = 'active';        // 通常在籍
    public const KIND_WITHDRAWN     = 'withdrawn';     // 退会済み（停止中・過去退会日）
    public const KIND_DORMANT       = 'dormant';       // 休会
    public const KIND_TICKET        = 'ticket';        // チケット会員/未対応プランの在籍（契約なし）
    public const KIND_INACTIVE_ZERO = 'inactive_zero'; // 定期購入OFF・実請求0の在籍

    /** 別システム表記 => 既存プラン名 */
    public const PLAN_ALIAS = [
        '（新）パーソナル＆セミパーソナル通い放題（2枠）' => 'パーソナル&セミパーソナル通い放題（2枠）',
        '【松山市駅前】パーソナル&セミパーソナル通い放題(1枠)' => 'パーソナル&セミパーソナル通い放題（1枠）',
        '（新）パーソナル＆セミパーソナル月4回' => 'パーソナル&セミパーソナル月4回',
        'パーソナル&セミパーソナル月4回（松山市駅前）' => 'パーソナル&セミパーソナル月4回',
        '（新）セミパーソナル通い放題' => 'セミパーソナル通い放題',
        'セミパーソナル通い放題（松山市駅前）' => 'セミパーソナル通い放題',
        'セミパーソナル通い放題（松山市駅前）（1年契約）' => 'セミパーソナル通い放題',
        'ペアプラン' => 'ペアプラン',
    ];

    /** プランではない課金ラベル（プラン解決時に読み飛ばす） */
    public const NON_PLAN_LABELS = ['休会プラン', 'チケット会員', 'スタッフ用アカウント'];

    public const STORE_ALIAS = ['ZEAL BOXING FITNESS 松山市駅前店' => '松山市駅前店'];

    public const GENDER_MAP = ['男性' => 'male', '女性' => 'female', 'その他' => 'other'];

    /**
     * @param array<string,int> $planIdMap    プラン名 => id
     * @param array<string,int> $planPriceMap プラン名 => 税抜定価
     * @param array<string,int> $storeIdMap   店舗名 => id
     */
    public function __construct(
        private array $planIdMap,
        private array $planPriceMap,
        private array $storeIdMap,
        private int $defaultStoreId,
        private float $taxRate = 10.0,
    ) {}

    /** @param array<string,string> $row */
    public static function isInScope(array $row): bool
    {
        return in_array(trim($row['状態'] ?? ''), ['会員', '停止中'], true);
    }
}
```

- [ ] **Step 4: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: PASS（1 test）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/MappedMember.php app/Support/Zeal/HacomonoMemberMapper.php tests/Unit/Zeal/HacomonoMemberMapperTest.php
git commit -m "feat(zeal): hacomono移行MapperのスケルトンとisInScopeを追加"
```

---

## Task 2: プラン解決（resolvePlan）

**Files:**
- Modify: `app/Support/Zeal/HacomonoMemberMapper.php`
- Test: `tests/Unit/Zeal/HacomonoMemberMapperTest.php`

- [ ] **Step 1: 失敗するテストを書く**（クラスに追記）

```php
    public function test_resolve_plan_maps_all_variants(): void
    {
        $m = $this->mapper();
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('（新）セミパーソナル通い放題', '')[0]);
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('セミパーソナル通い放題（松山市駅前）（1年契約）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル月4回', $m->resolvePlan('パーソナル&セミパーソナル月4回（松山市駅前）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル通い放題（1枠）', $m->resolvePlan('【松山市駅前】パーソナル&セミパーソナル通い放題(1枠)', '')[0]);
        $this->assertSame('ペアプラン', $m->resolvePlan('ペアプラン', '')[0]);
    }

    public function test_resolve_plan_prefers_custom2_then_course(): void
    {
        $m = $this->mapper();
        // カスタム2 が空なら コース名前 を使う
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('', '（新）セミパーソナル通い放題')[0]);
        // カスタム2 が NON_PLAN ラベルなら次へ（実データでは起きにくいが安全側）
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('休会プラン', 'セミパーソナル通い放題（松山市駅前）')[0]);
    }

    public function test_resolve_plan_returns_null_for_unmatched(): void
    {
        $m = $this->mapper();
        [$name, $raw, $src] = $m->resolvePlan('チケット会員', '');
        $this->assertNull($name);
        $this->assertSame('チケット会員', $raw);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: FAIL（`Call to undefined method ...::resolvePlan()`）

- [ ] **Step 3: 実装を追加**（`HacomonoMemberMapper` にメソッド追加）

```php
    /**
     * カスタム2 を主、空/NON_PLAN なら コース名前 を従として既存プラン名へ解決。
     * @return array{0:?string,1:string,2:string} [プラン名(未解決はnull), 元表記, 取得元]
     */
    public function resolvePlan(string $custom2, string $course): array
    {
        foreach (['カスタム2' => $custom2, 'コース名前' => $course] as $src => $raw) {
            $raw = trim($raw);
            if ($raw === '' || in_array($raw, self::NON_PLAN_LABELS, true)) {
                continue;
            }
            return [self::PLAN_ALIAS[$raw] ?? null, $raw, $src];
        }
        $raw = trim($custom2) !== '' ? trim($custom2) : trim($course);
        return [null, $raw, 'none'];
    }
```

- [ ] **Step 4: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: PASS（4 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/HacomonoMemberMapper.php tests/Unit/Zeal/HacomonoMemberMapperTest.php
git commit -m "feat(zeal): プラン名エイリアス解決(resolvePlan)を実装"
```

---

## Task 3: 日付・金額・性別の正規化ヘルパー

**Files:**
- Modify: `app/Support/Zeal/HacomonoMemberMapper.php`
- Test: `tests/Unit/Zeal/HacomonoMemberMapperTest.php`

- [ ] **Step 1: 失敗するテストを書く**（追記）

```php
    public function test_normalize_date(): void
    {
        $m = $this->mapper();
        $this->assertSame('2025-10-17', $m->normalizeDate('2025/10/17'));
        $this->assertSame('2026-04-01', $m->normalizeDate('2026/4/1'));
        $this->assertNull($m->normalizeDate(''));
        $this->assertNull($m->normalizeDate('not-a-date'));
    }

    public function test_to_int(): void
    {
        $m = $this->mapper();
        $this->assertSame(9702, $m->toInt('9702'));
        $this->assertNull($m->toInt(''));
        $this->assertNull($m->toInt('abc'));
        $this->assertSame(0, $m->toInt('0'));
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: FAIL（`undefined method normalizeDate/toInt`）

- [ ] **Step 3: 実装を追加**

```php
    public function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    public function toInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            return null;
        }
        return (int) $value;
    }
```

- [ ] **Step 4: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: PASS（6 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/HacomonoMemberMapper.php tests/Unit/Zeal/HacomonoMemberMapperTest.php
git commit -m "feat(zeal): 日付/数値の正規化ヘルパーを実装"
```

---

## Task 4: map() — 通常在籍・性別・店舗・入会日必須

**Files:**
- Modify: `app/Support/Zeal/HacomonoMemberMapper.php`
- Test: `tests/Unit/Zeal/HacomonoMemberMapperTest.php`

map() は 77列のうち使用列のみ参照する。テストでは必要キーだけ持つ行配列を作る。未指定キーは `?? ''` で空扱いになるよう実装する。

- [ ] **Step 1: 失敗するテストを書く**（追記）

```php
    /** @return array<string,string> 使用列だけを持つ行（他は空） */
    private function row(array $over): array
    {
        return array_merge([
            'ID' => 'CL00000001', '状態' => '会員', '定期購入' => 'TRUE',
            '名前' => '山田 太郎', '名前カナ' => 'ヤマダ タロウ', '性別' => '男性',
            '生年月日' => '1990/1/2', '電話番号' => '', 'メールアドレス' => '',
            '郵便番号' => '', '住所' => '', '入会日' => '2025/10/17',
            'カスタム2' => '（新）セミパーソナル通い放題', 'コース 名前' => '（新）セミパーソナル通い放題',
            'コース 名前（内部）' => '', '変更後コース 名前' => '',
            '合計金額(2回目以降)' => '9702', 'コース 合計金額(2回目以降)' => '10780',
            '退会日' => '', '退会予定日' => '', '残チケット数' => '0',
            '紹介コード' => '', '顧客内部カルテ' => '', '店舗 名前' => 'ZEAL BOXING FITNESS 松山市駅前店',
        ], $over);
    }

    public function test_map_normal_active_member(): void
    {
        $r = $this->mapper()->map($this->row([]));
        $this->assertSame(HacomonoMemberMapper::KIND_ACTIVE, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertSame('セミパーソナル通い放題', $r->planName);
        // 9702(税込) / 1.1 = 8820(税抜)
        $this->assertSame(8820, $r->appliedPriceExcl);
        $this->assertSame('male', $r->memberAttributes['gender']);
        $this->assertSame(1, $r->memberAttributes['store_id']);
        $this->assertSame(4, $r->memberAttributes['current_plan_id']);
        $this->assertSame('2025-10-17', $r->memberAttributes['joined_on']);
        // 契約: 在籍は period_end=null
        $this->assertNotNull($r->contractAttributes);
        $this->assertSame(4, $r->contractAttributes['plan_id']);
        $this->assertNull($r->contractAttributes['period_end']);
        $this->assertSame(8820, $r->contractAttributes['applied_price_excl']);
        $this->assertSame('new_join', $r->contractAttributes['change_reason']);
    }

    public function test_map_blank_gender_is_warning_null(): void
    {
        $r = $this->mapper()->map($this->row(['性別' => '']));
        $this->assertFalse($r->hasErrors());
        $this->assertNull($r->memberAttributes['gender']);
        $this->assertNotEmpty($r->warnings);
    }

    public function test_map_missing_joined_on_is_error(): void
    {
        $r = $this->mapper()->map($this->row(['入会日' => '']));
        $this->assertTrue($r->hasErrors());
    }

    public function test_map_store_alias_and_fallback(): void
    {
        $r = $this->mapper()->map($this->row(['店舗 名前' => '不明な店舗']));
        $this->assertSame(1, $r->memberAttributes['store_id']); // フォールバック=defaultStoreId
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: FAIL（`undefined method map()`）

- [ ] **Step 3: map() を実装**（区分判定は Task 5 で拡張する。まず通常在籍＋エラー/警告＋契約を実装）

```php
    /** @param array<string,string> $row */
    public function map(array $row): MappedMember
    {
        $get = fn (string $k): string => trim($row[$k] ?? '');
        $errors = [];
        $warnings = [];

        $sourceId = $get('ID');
        $status   = $get('状態');
        $name     = $get('名前');
        if ($name === '') {
            $errors[] = '氏名が空です';
        }

        $joinedRaw = $get('入会日');
        $joinedOn  = $this->normalizeDate($joinedRaw);
        if ($joinedOn === null) {
            $errors[] = $joinedRaw === '' ? '入会日が空です（必須）' : "入会日'{$joinedRaw}'の形式が不正";
        }

        $genderRaw = $get('性別');
        $gender = null;
        if ($genderRaw === '') {
            $warnings[] = '性別が空（null取込）';
        } elseif (isset(self::GENDER_MAP[$genderRaw])) {
            $gender = self::GENDER_MAP[$genderRaw];
        } else {
            $errors[] = "性別'{$genderRaw}'が不正";
        }

        $storeRaw  = $get('店舗 名前');
        $storeName = self::STORE_ALIAS[$storeRaw] ?? $storeRaw;
        $storeId   = $this->storeIdMap[$storeName] ?? $this->defaultStoreId;

        [$planName, $rawPlan] = $this->resolvePlan($get('カスタム2'), $get('コース 名前'));
        $planId = $planName !== null ? ($this->planIdMap[$planName] ?? null) : null;

        $paidIncl       = $this->toInt($get('合計金額(2回目以降)'));
        $courseListIncl = $this->toInt($get('コース 合計金額(2回目以降)'));
        $withdrewOn     = $this->normalizeDate($get('退会日'));
        $scheduledOn    = $get('退会予定日');

        // --- 区分判定（Task 5 で分岐を追加）---
        $kind = self::KIND_ACTIVE;
        $priceExcl = $this->taxExcl($paidIncl);
        if ($priceExcl === null) {
            $errors[] = '月会費(合計金額)が空/不正です';
        }

        $isScheduled = ($scheduledOn !== '');
        if ($isScheduled) {
            $warnings[] = "退会予定日 {$scheduledOn}";
        }

        $memo = $this->buildMemo($sourceId, $get, $scheduledOn, $kind);

        $contract = null;
        if ($planId !== null && $kind !== self::KIND_TICKET) {
            $contract = [
                'plan_id'            => $planId,
                'period_start'       => $joinedOn,
                'period_end'         => $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
                'applied_price_excl' => $priceExcl,
                'change_reason'      => 'new_join',
            ];
        }

        $member = [
            'store_id'           => $storeId,
            'name'               => $name,
            'name_kana'          => $get('名前カナ'),
            'gender'             => $gender,
            'birthday'           => $this->normalizeDate($get('生年月日')),
            'phone'              => $get('電話番号') ?: null,
            'email'              => $get('メールアドレス') ?: null,
            'postal_code'        => $get('郵便番号') ?: null,
            'address'            => $get('住所') ?: null,
            'joined_on'          => $joinedOn,
            'current_plan_id'    => $kind === self::KIND_TICKET ? null : $planId,
            'trainer_id'         => null,
            'acquisition_source' => null,
            'purpose'            => null,
            'withdrew_on'        => $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
            'withdraw_reason'    => null,
            'withdraw_note'      => $kind === self::KIND_WITHDRAWN ? '別システムより移管（退会済み）' : null,
            'memo'               => $memo,
        ];

        return new MappedMember(
            sourceId: $sourceId,
            displayName: $name,
            status: $status,
            kind: $kind,
            planName: $planName,
            rawPlan: $rawPlan,
            sourceAmountIncl: $paidIncl,
            courseListIncl: $courseListIncl,
            appliedPriceExcl: $priceExcl,
            withdrewOn: $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
            scheduledOn: $isScheduled ? $scheduledOn : null,
            memberAttributes: $member,
            contractAttributes: $contract,
            warnings: $warnings,
            errors: $errors,
        );
    }

    private function taxExcl(?int $incl): ?int
    {
        return $incl === null ? null : (int) round($incl / (1 + $this->taxRate / 100));
    }

    /** @param callable(string):string $get */
    private function buildMemo(string $sourceId, callable $get, string $scheduledOn, string $kind): string
    {
        $lines = [];
        if ($sourceId !== '') {
            $lines[] = "移行元ID: {$sourceId}";
        }
        if (preg_match('/割引名:\s*(.+)/u', $get('顧客内部カルテ'), $mm)) {
            $lines[] = '割引名: ' . trim($mm[1]);
        }
        if ($scheduledOn !== '') {
            $lines[] = "退会予定日: {$scheduledOn}";
        }
        if ($get('紹介コード') !== '') {
            $lines[] = '紹介コード: ' . $get('紹介コード');
        }
        return implode("\n", $lines);
    }
```

- [ ] **Step 4: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: PASS（10 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/HacomonoMemberMapper.php tests/Unit/Zeal/HacomonoMemberMapperTest.php
git commit -m "feat(zeal): map()の通常在籍・性別/店舗/入会日・契約生成を実装"
```

---

## Task 5: map() — 退会済み・休会・チケット・定期購入OFF0円の区分

**Files:**
- Modify: `app/Support/Zeal/HacomonoMemberMapper.php`
- Test: `tests/Unit/Zeal/HacomonoMemberMapperTest.php`

- [ ] **Step 1: 失敗するテストを書く**（追記）

```php
    public function test_map_suspended_is_withdrawn_with_plan_list_price(): void
    {
        // 停止中: コース名前は空、カスタム2 にプラン、退会日あり、合計金額0
        $r = $this->mapper()->map($this->row([
            '状態' => '停止中', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）',
            'コース 名前' => '', '合計金額(2回目以降)' => '0', '退会日' => '2026/6/1', '定期購入' => 'FALSE',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_WITHDRAWN, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertSame(9800, $r->appliedPriceExcl); // プラン定価(税抜)
        $this->assertSame('2026-06-01', $r->memberAttributes['withdrew_on']);
        $this->assertSame('2026-06-01', $r->contractAttributes['period_end']);
        $this->assertSame(9800, $r->contractAttributes['applied_price_excl']);
    }

    public function test_map_member_with_past_withdraw_date_is_withdrawn(): void
    {
        $r = $this->mapper()->map($this->row(['状態' => '会員', '退会日' => '2026/4/1']));
        $this->assertSame(HacomonoMemberMapper::KIND_WITHDRAWN, $r->kind);
        $this->assertSame('2026-04-01', $r->memberAttributes['withdrew_on']);
    }

    public function test_map_dormant_uses_actual_dormancy_fee(): void
    {
        // 休会: コース名前=休会プラン, カスタム2 に実プラン, 合計金額=1100
        $r = $this->mapper()->map($this->row([
            'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '休会プラン',
            '合計金額(2回目以降)' => '1100',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_DORMANT, $r->kind);
        $this->assertSame('セミパーソナル通い放題', $r->planName);
        $this->assertSame(1000, $r->appliedPriceExcl); // 1100/1.1
        $this->assertNull($r->contractAttributes['period_end']); // 在籍
        $this->assertStringContainsString('休会', $r->memberAttributes['memo']);
    }

    public function test_map_ticket_member_has_no_plan_and_no_contract(): void
    {
        $r = $this->mapper()->map($this->row([
            '状態' => '会員', '定期購入' => 'FALSE', 'カスタム2' => '', 'コース 名前' => 'チケット会員',
            '合計金額(2回目以降)' => '0', '残チケット数' => '4',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_TICKET, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertNull($r->memberAttributes['current_plan_id']);
        $this->assertNull($r->contractAttributes); // 契約なし
        $this->assertStringContainsString('チケット会員', $r->memberAttributes['memo']);
    }

    public function test_map_inactive_zero_uses_plan_list_price(): void
    {
        // 定期購入OFF・実請求0・プラン判明 → プラン定価
        $r = $this->mapper()->map($this->row([
            '状態' => '会員', '定期購入' => 'FALSE',
            'カスタム2' => 'パーソナル&セミパーソナル月4回（松山市駅前）',
            'コース 名前' => 'パーソナル&セミパーソナル月4回（松山市駅前）', '合計金額(2回目以降)' => '0',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_INACTIVE_ZERO, $r->kind);
        $this->assertSame(13000, $r->appliedPriceExcl);
        $this->assertNull($r->contractAttributes['period_end']);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: FAIL（区分が ACTIVE のままになる等のアサーション失敗）

- [ ] **Step 3: 区分判定ブロックを差し替える**

`map()` 内の `// --- 区分判定（Task 5 で分岐を追加）---` から `$memo = ...` の直前までを、以下に置き換える:

```php
        // --- 区分判定（優先順）---
        $teikiOff   = strtoupper($get('定期購入')) === 'FALSE';
        $isDormant  = $get('コース 名前') === '休会プラン' || $get('変更後コース 名前') === '休会プラン';
        $paidIsZero = ($paidIncl === null || $paidIncl === 0);

        if ($status === '停止中' || $withdrewOn !== null) {
            // 1. 退会済み → プラン定価(税抜)・契約クローズ
            $kind = self::KIND_WITHDRAWN;
            $priceExcl = $planName !== null ? ($this->planPriceMap[$planName] ?? null) : null;
            if ($priceExcl === null) {
                $errors[] = "退会者だがプラン未解決: '{$rawPlan}'";
            }
        } elseif ($isDormant) {
            // 2. 休会 → 実際の休会費(税抜)
            $kind = self::KIND_DORMANT;
            if ($planName === null) {
                $errors[] = "休会だが実プラン未解決: '{$rawPlan}'";
            }
            $priceExcl = $this->taxExcl($paidIncl) ?? 0;
        } elseif ($planName === null) {
            // 3. チケット会員/未対応プランの在籍 → 契約なし
            $kind = self::KIND_TICKET;
            $priceExcl = null;
            $warnings[] = "プラン未対応（'{$rawPlan}'）→会員のみ作成・契約なし";
        } elseif ($teikiOff && $paidIsZero) {
            // 4. 定期購入OFF・実請求0 → プラン定価(税抜)
            $kind = self::KIND_INACTIVE_ZERO;
            $priceExcl = $this->planPriceMap[$planName] ?? null;
            $warnings[] = '定期購入なし（実請求0）→プラン定価';
        } else {
            // 5. 通常在籍 → 実請求(税抜)
            $kind = self::KIND_ACTIVE;
            $priceExcl = $this->taxExcl($paidIncl);
            if ($priceExcl === null) {
                $errors[] = '月会費(合計金額)が空/不正です';
            }
        }
```

また `buildMemo()` に区分別の補足を追加する。`buildMemo` の `return` 直前に挿入:

```php
        if ($kind === self::KIND_DORMANT) {
            $lines[] = '区分: 休会中（移管時点）';
        }
        if ($kind === self::KIND_TICKET) {
            $lines[] = '区分: チケット会員（残' . $get('残チケット数') . '枚・定期購入なし）';
        }
        if ($kind === self::KIND_INACTIVE_ZERO) {
            $lines[] = '区分: 定期購入なし（移管時・チケット残' . $get('残チケット数') . '）';
        }
```

- [ ] **Step 4: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoMemberMapperTest`
Expected: PASS（15 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/HacomonoMemberMapper.php tests/Unit/Zeal/HacomonoMemberMapperTest.php
git commit -m "feat(zeal): 退会済み/休会/チケット/定期購入OFFの区分判定を実装"
```

---

## Task 6: HacomonoCsvReader（文字コード・BOM・引用内改行）

**Files:**
- Create: `app/Support/Zeal/HacomonoCsvReader.php`
- Create: `tests/fixtures/zeal/reader_sample.csv`
- Test: `tests/Unit/Zeal/HacomonoCsvReaderTest.php`

- [ ] **Step 1: フィクスチャを作る**

`tests/fixtures/zeal/reader_sample.csv`（先頭にUTF-8 BOM、2行目の備考に引用内改行を含む）。以下の内容を作成（先頭の `\xEF\xBB\xBF` BOM を必ず付与。エディタで付与できない場合は Step 1 後半の生成コマンドを使う）:

```
ID,状態,備考
CL001,会員,"1行目
2行目"
CL002,停止中,普通
```

BOM 付与が手作業で難しい場合は次で生成:

```bash
mkdir -p tests/fixtures/zeal
printf '\xEF\xBB\xBFID,状態,備考\r\nCL001,会員,"1行目\r\n2行目"\r\nCL002,停止中,普通\r\n' > tests/fixtures/zeal/reader_sample.csv
```

- [ ] **Step 2: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Zeal;

use App\Support\Zeal\HacomonoCsvReader;
use PHPUnit\Framework\TestCase;

class HacomonoCsvReaderTest extends TestCase
{
    public function test_reads_header_mapped_rows_with_bom_and_multiline(): void
    {
        $rows = HacomonoCsvReader::read(__DIR__ . '/../../fixtures/zeal/reader_sample.csv');

        $this->assertCount(2, $rows);
        // BOM がヘッダーキーに混入しないこと
        $this->assertSame('CL001', $rows[0]['ID']);
        $this->assertSame('会員', $rows[0]['状態']);
        // 引用フィールド内の改行が保持されること
        $this->assertStringContainsString("1行目", $rows[0]['備考']);
        $this->assertStringContainsString("2行目", $rows[0]['備考']);
        $this->assertSame('停止中', $rows[1]['状態']);
    }
}
```

- [ ] **Step 3: 失敗を確認**

Run: `vendor/bin/phpunit --filter HacomonoCsvReaderTest`
Expected: FAIL（`Class HacomonoCsvReader not found`）

- [ ] **Step 4: 実装**

```php
<?php

namespace App\Support\Zeal;

class HacomonoCsvReader
{
    /**
     * hacomono形式CSVを連想配列の配列に変換する。
     * - 文字コード自動判定（UTF-8 / SJIS-win / SJIS / EUC-JP）→ UTF-8 へ変換
     * - 先頭 BOM 除去
     * - 引用フィールド内の改行に対応（fgetcsv 使用）
     * - 各行はヘッダー名をキーにした配列。列数が足りない場合は空文字で補完。
     *
     * @return array<int,array<string,string>>
     */
    public static function read(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("CSVを読み込めません: {$path}");
        }

        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);

        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            return [];
        }
        $colCount = count($header);

        $rows = [];
        while (($cells = fgetcsv($fh)) !== false) {
            // 完全な空行はスキップ
            if (count(array_filter($cells, static fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            $cells = array_slice($cells, 0, $colCount);
            $cells = array_pad($cells, $colCount, '');
            $rows[] = array_combine($header, array_map(static fn ($c) => (string) ($c ?? ''), $cells));
        }
        fclose($fh);

        return $rows;
    }
}
```

- [ ] **Step 5: テストが通るのを確認**

Run: `vendor/bin/phpunit --filter HacomonoCsvReaderTest`
Expected: PASS（1 test）

- [ ] **Step 6: コミット**

```bash
git add app/Support/Zeal/HacomonoCsvReader.php tests/Unit/Zeal/HacomonoCsvReaderTest.php tests/fixtures/zeal/reader_sample.csv
git commit -m "feat(zeal): hacomono CSVリーダー(文字コード/BOM/引用内改行)を実装"
```

---

## Task 7: ZealImportMembersCommand（dry-run プレビュー）

**Files:**
- Create: `app/Console/Commands/ZealImportMembersCommand.php`

DBへ書き込まない dry-run を先に作る。Laravel 12 は `app/Console/Commands` を自動登録する。

- [ ] **Step 1: コマンドを実装（dry-run のみ。commit は Task 8）**

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Support\Settings;
use App\Support\Zeal\HacomonoCsvReader;
use App\Support\Zeal\HacomonoMemberMapper;
use App\Support\Zeal\MappedMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZealImportMembersCommand extends Command
{
    protected $signature = 'zeal:import-members {path : hacomono形式CSVのパス}
        {--commit : 実際にDBへ投入（未指定はdry-run）}
        {--actor=m-saiki@mitsuwat.co.jp : 登録者(created_by)のメールアドレス}';

    protected $description = 'hacomono形式CSVからZEAL会員を移行（既定はdry-run）';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("ファイルが見つかりません: {$path}");
            return self::FAILURE;
        }

        $actor = User::where('email', $this->option('actor'))->first();
        if (!$actor) {
            $this->error("登録者ユーザーが見つかりません: {$this->option('actor')}");
            return self::FAILURE;
        }

        $rows = HacomonoCsvReader::read($path);

        $planIdMap    = ZealPlan::pluck('id', 'name')->toArray();
        $planPriceMap = ZealPlan::pluck('regular_price_excl', 'name')->toArray();
        $storeIdMap   = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)->orderBy('display_order')->orderBy('id')->first();
        if (!$defaultStore) {
            $this->error('有効な店舗がありません');
            return self::FAILURE;
        }
        $taxRate = Settings::taxRate();

        $mapper = new HacomonoMemberMapper($planIdMap, $planPriceMap, $storeIdMap, $defaultStore->id, $taxRate);

        /** @var MappedMember[] $toImport */
        $toImport = [];
        /** @var MappedMember[] $skipped */
        $skipped = [];
        /** @var MappedMember[] $errored */
        $errored = [];

        foreach ($rows as $row) {
            if (!HacomonoMemberMapper::isInScope($row)) {
                continue;
            }
            $m = $mapper->map($row);

            if (!$m->hasErrors()
                && ZealMember::where('name', $m->displayName)
                    ->where('joined_on', $m->memberAttributes['joined_on'])
                    ->exists()
            ) {
                $skipped[] = $m;
                continue;
            }
            $m->hasErrors() ? $errored[] = $m : $toImport[] = $m;
        }

        $this->renderPreview($toImport, $skipped, $errored);

        if (!$this->option('commit')) {
            $this->info('dry-run（投入するには --commit を付けて再実行）');
            return self::SUCCESS;
        }

        return $this->commit($toImport, $errored, $actor->id, $taxRate);
    }

    /**
     * @param MappedMember[] $toImport
     * @param MappedMember[] $skipped
     * @param MappedMember[] $errored
     */
    private function renderPreview(array $toImport, array $skipped, array $errored): void
    {
        $kindLabel = [
            HacomonoMemberMapper::KIND_ACTIVE        => '在籍',
            HacomonoMemberMapper::KIND_WITHDRAWN     => '退会済',
            HacomonoMemberMapper::KIND_DORMANT       => '休会',
            HacomonoMemberMapper::KIND_TICKET        => 'チケット',
            HacomonoMemberMapper::KIND_INACTIVE_ZERO => '定期OFF',
        ];

        $tableRows = [];
        foreach ($toImport as $m) {
            $tableRows[] = [
                $m->sourceId,
                $m->displayName,
                $m->status,
                $kindLabel[$m->kind] ?? $m->kind,
                $m->planName ?? "（未対応:{$m->rawPlan}）",
                $m->sourceAmountIncl ?? '-',
                $m->courseListIncl ?? '-',
                $m->appliedPriceExcl ?? '-',
                $m->withdrewOn ?? $m->scheduledOn ?? '',
                implode(' / ', $m->warnings),
            ];
        }

        $this->table(
            ['元ID', '氏名', '状態', '区分', 'プラン', '元金額', '定価', '税抜', '退会(予定)', '警告'],
            $tableRows
        );

        if ($errored) {
            $this->error('--- エラー行（取込しない） ---');
            foreach ($errored as $m) {
                $this->line("  {$m->sourceId} {$m->displayName}: " . implode(' / ', $m->errors));
            }
        }
        if ($skipped) {
            $this->warn('--- スキップ（同名・同入会日が既存） ---');
            foreach ($skipped as $m) {
                $this->line("  {$m->sourceId} {$m->displayName}");
            }
        }

        $this->info(sprintf(
            '取込予定 %d 件 / スキップ %d 件 / エラー %d 件',
            count($toImport), count($skipped), count($errored)
        ));
    }

    /**
     * @param MappedMember[] $toImport
     * @param MappedMember[] $errored
     */
    private function commit(array $toImport, array $errored, int $actorId, float $taxRate): int
    {
        if ($errored) {
            $this->error('エラー行があるため中断しました。元CSVを修正して再実行してください。');
            return self::FAILURE;
        }
        if (!$this->confirm(count($toImport) . ' 件を本当に投入しますか？')) {
            $this->info('中止しました。');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($toImport, $actorId, $taxRate) {
            foreach ($toImport as $m) {
                $member = ZealMember::create($m->memberAttributes + [
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                if ($m->contractAttributes !== null) {
                    ZealMemberContract::create($m->contractAttributes + [
                        'member_id'            => $member->id,
                        'is_campaign_applied'  => false,
                        'tax_rate_at_contract' => $taxRate,
                        'created_by'           => $actorId,
                    ]);
                }
            }
        });

        $this->info(count($toImport) . ' 件を取り込みました。');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: 構文チェック**

Run: `php -l app/Console/Commands/ZealImportMembersCommand.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コマンド登録確認**

Run: `php artisan list | grep zeal:import-members`
Expected: `zeal:import-members` が表示される

- [ ] **Step 4: dry-run を実データなしで動作確認（添付サンプルCSVで）**

Run: `php artisan zeal:import-members /Users/masanori/Desktop/clients_csv_JT8789776487.csv`
Expected:
- プレビュー表が出力される
- サマリが「取込予定 33 件 / スキップ 0 件 / エラー 2 件前後」付近（**氏名空のためエラーになる行がある**＝個人情報なしサンプルでは氏名空がエラーになる点に注意。区分・プラン・金額の判定が正しく出ていることを目視確認する）
- **DBは変化しない**（`--commit` なし）

> 注: 添付サンプルは氏名が空のため「氏名が空です」エラーが出るのが正常。区分（退会済4・休会1・チケット1・定期OFF2）とプラン解決・税抜額が設計どおりか確認することが目的。本番は氏名入り実データでエラー0になる想定。

- [ ] **Step 5: コミット**

```bash
git add app/Console/Commands/ZealImportMembersCommand.php
git commit -m "feat(zeal): 会員移行コマンドのdry-runプレビューを実装"
```

---

## Task 8: ローカル実DBリハーサル（commit 経路の検証）

**Files:** （コード変更なし。動作検証とドキュメント）

phpunit でDB書込はテストしない（ZEALテーブルは raw SQL 管理で RefreshDatabase 不可）。代わりにローカル実DBで commit 経路を検証する。**氏名入りの検証用CSV**を一時的に用意する（添付サンプルの氏名空をダミー氏名で埋めたもの）。

- [ ] **Step 1: 検証用CSVを用意**

添付サンプルをコピーし、`名前`・`名前カナ`・`性別` 列にダミー値を入れた `/tmp/zeal_rehearsal.csv` を作る（手作業またはスクリプト）。最低限、会員/停止中の35行に氏名・カナ・性別が入っていればよい。

- [ ] **Step 2: dry-run でエラー0を確認**

Run: `php artisan zeal:import-members /tmp/zeal_rehearsal.csv`
Expected: エラー 0 件 / 取込予定 35 件。プレビューの区分内訳が「在籍27（予定3含む）・退会済4・休会1・チケット1・定期OFF2」。

- [ ] **Step 3: commit 実行**

Run: `php artisan zeal:import-members /tmp/zeal_rehearsal.csv --commit`（確認プロンプトに yes）
Expected: 「35 件を取り込みました。」

- [ ] **Step 4: 投入結果を検証**

Run:
```bash
php artisan tinker --execute='
echo "members=".\App\Models\ZealMember::count().PHP_EOL;
echo "contracts=".\App\Models\ZealMemberContract::count().PHP_EOL;
echo "withdrawn=".\App\Models\ZealMember::whereNotNull("withdrew_on")->count().PHP_EOL;
echo "plan_null=".\App\Models\ZealMember::whereNull("current_plan_id")->count().PHP_EOL;
echo "open_contracts=".\App\Models\ZealMemberContract::whereNull("period_end")->count().PHP_EOL;
'
```
Expected: `members=35` / `contracts=34`（チケット会員は契約なし）/ `withdrawn=4` / `plan_null=1`（チケット会員）/ `open_contracts=30`（35-4退会-1チケット=30）。

- [ ] **Step 5: 再実行で冪等性を確認**

Run: `php artisan zeal:import-members /tmp/zeal_rehearsal.csv`（dry-run）
Expected: スキップ 35 件 / 取込予定 0 件（同名・同入会日で全てスキップ）。

- [ ] **Step 6: ブラウザでZEAL会員一覧を確認**

ローカルの `/zeal/members` を開き、会員一覧・退会者・休会メモ・月会費が設計どおり表示されることを目視確認（preview_* ツールが使えればスクリーンショット）。

- [ ] **Step 7: ローカルDBを初期状態へ戻す**

Run:
```bash
php artisan tinker --execute='
\App\Models\ZealMemberContract::query()->delete();
\App\Models\ZealMember::query()->delete();
echo "reset done: members=".\App\Models\ZealMember::count().PHP_EOL;
'
rm -f /tmp/zeal_rehearsal.csv
```
Expected: `members=0`。検証用CSV削除（個人情報の後片付け）。

- [ ] **Step 8: 全テストを通す**

Run: `vendor/bin/phpunit tests/Unit/Zeal`
Expected: 全 PASS。

---

## Task 9: セルフレビューと本番反映準備

**Files:** （レビュー＋マージ準備）

- [ ] **Step 1: コードレビュー**

`/review`（code-review プラグイン）で feature ブランチの差分をセルフレビュー。特に過去バグ観点（@json属性・Tailwind未ビルドクラスは本機能では非該当だが、Enumキャスト属性へ tryFrom 誤用がないか＝本機能は文字列で create するため該当しないことを確認）。

- [ ] **Step 2: dev依存を戻す**

Run: `composer install --no-dev`
Expected: dev パッケージが除去される（本番 vendor と同等へ）。

- [ ] **Step 3: 13.x へ FF マージ**

```bash
git checkout 13.x
git merge --ff-only feature/zeal-hacomono-import
```

- [ ] **Step 4: オートローダ再生成（新規クラスのため・main repo の cwd で）**

Run: `composer dump-autoload`
Expected: 生成完了。`vendor/composer/autoload_classmap.php` 等が更新。

- [ ] **Step 5: デプロイ**

Run: `./deploy.sh`
Expected: rsync ＋ 本番で config/route/view キャッシュ再生成。コマンドクラスが本番へ配置される。

- [ ] **Step 6: 本番反映の確認**

Run: `ssh mitsuwa-ud@www3586.sakura.ne.jp '/bin/sh -c "cd <本番アプリパス> && php artisan list | grep zeal:import-members"'`
Expected: 本番で `zeal:import-members` が登録済み。

---

## 本番取り込み手順（実データ・別途ユーザー承認のうえ実施）

> コード反映後、**氏名入り本番CSV**をユーザーから受領してから実施。本番DB書込のため、各ステップでユーザーの明示承認を得る。

1. 本番へ実データCSVを安全に転送（`rsync` で本番の `storage/app/zeal_import.csv` 等、Web公開されない場所へ）。
2. `ssh ... 'cd <path> && php artisan zeal:import-members storage/app/zeal_import.csv'`（dry-run）→ **プレビューをユーザーが確認**。税抜額・区分・件数を検証。
3. 問題なければ `--commit` を付けて再実行（確認プロンプトに yes）。
4. 本番 `/zeal/members` で件数・内容を確認。
5. **本番のCSVを削除**（`ssh ... 'rm storage/app/zeal_import.csv'`）= 個人情報の後片付け。

---

## Self-Review（プラン作成者によるスペック突合）

- **スペック §4 スコープ**（会員+停止中35）→ Task 1 `isInScope`＋Task 7 フィルタ。✔
- **§5.2 プラン解決**（エイリアス・カスタム2優先）→ Task 2。✔
- **§5.3 店舗**（エイリアス＋フォールバック）→ Task 4 `test_map_store_alias_and_fallback`。✔
- **§5.4 月会費**（÷1.1・退会/0円=定価・休会=実費）→ Task 4/5。✔ 税込/税抜混在リスクは Task 7 dry-run の目視で担保。✔
- **§5.5 区分**（退会済/休会/チケット/定期OFF/在籍/退会予定）→ Task 5 各テスト＋Task 4 予定日警告。✔
- **§5.6 メモ**（元ID・割引名・退会予定日・紹介コード・区分）→ Task 4 `buildMemo`＋Task 5 区分補足。✔
- **§5.7 登録者**（actorメール解決）→ Task 7。✔
- **§6 バリデーション**（入会日必須=エラー/性別空=警告）→ Task 4。✔
- **§7 コマンド**（dry-run/commit/冪等性）→ Task 7/8。✔
- **§8 アーキテクチャ**（Mapper/Reader/Command 3層）→ Task 1-7。✔
- **§9 テスト方針**（純粋部分phpunit・commitはリハーサル）→ Task 1-6（phpunit）/Task 8（リハーサル）。✔
- **§10 ロールアウト**→ Task 9＋本番手順。✔

型整合: `MappedMember` の各 readonly プロパティ名は Task 7 の `renderPreview`/`commit` 参照と一致（`sourceId`/`displayName`/`kind`/`planName`/`rawPlan`/`sourceAmountIncl`/`courseListIncl`/`appliedPriceExcl`/`withdrewOn`/`scheduledOn`/`memberAttributes`/`contractAttributes`/`warnings`/`errors`）。`KIND_*` 定数は Task 1 定義を Task 5/7 で参照。プレースホルダ無し。
