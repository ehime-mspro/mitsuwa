# ZEAL 会員CSVインポート 新フォーマット対応 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Web UI「ZEAL 会員 CSVインポート」を、別システムが出力する 77 列形式の会員 CSV 専用へ置換する（旧16列テンプレート機能は廃止）。

**Architecture:** 2026-06-15 の移行で実証済み（24 PHPUnit）の `HacomonoCsvReader` + `HacomonoMemberMapper` をそのまま Web 取込へ流用する。区分判定（在籍/退会済/休会/チケット/定期OFF）・プラン名解決・税抜判別はコアに委譲し、変更は **Web 層（コントローラ + 2 Blade + ルート）とテスト** に限定する。コアの Reader/Mapper/MappedMember とそのテスト・移行コマンドは触らない。

**Tech Stack:** Laravel 12 / PHP 8.3(prod)・8.5(local) / Blade（inline style 厳守・Vite ビルド済 Tailwind 制約） / PHPUnit（SQLite in-memory + RefreshDatabase）。

**設計書（入力）:** `docs/superpowers/specs/2026-06-24-zeal-member-csv-new-format-design.md`

---

## 前提・重要な制約（実装前に必ず読む）

1. **`zeal_*` テーブルは Laravel マイグレーション管理外**（raw SQL DDL = `database/sql/create_zeal_tables.sql`）。
   `RefreshDatabase`（SQLite in-memory）は migration しか流さないため、Feature テストで zeal テーブルは作られない。
   → **本プランは `Schema::create` で 4 表をテスト時に構築する trait（`tests/Concerns/CreatesZealSchema.php`）を新設**して解決する（ユーザー承認済み方針）。
2. **`Settings::taxRate()` はテーブル不在でも 10.0 を返す**（try/catch フォールバック）。テストに `settings` テーブルは不要。
3. **ルート認可:** `member-import` 系は `Route::middleware(['auth','password.change'])` → `Route::middleware('role:executive')->prefix('admin')` の二重グループ内。
   テストユーザーは `User::factory()->create(['role' => 'executive', 'must_change_password' => false])`。URL プレフィックスは `/admin`（例: `/admin/zeal/member-import`）。
4. **テストは main repo で実行**（worktree に vendor 無し）:
   `composer install`（dev込）→ `vendor/bin/phpunit`（または対象クラス指定）→ 完了後 `composer install --no-dev` で本番同等に戻す。
5. **Blade 検証は `view:cache` 成功で満足しない（Bug #26）**。必ずコンパイル済みビューを `php -l` する（Task 7）。
6. **Bug #7/#23/#26 回避:** 新 Blade では `@json()` に多行配列・関数呼び出しを渡さない／`x-data` 属性内に `@json` を入れない。本プランの Blade は Alpine 不使用・`@foreach` で静的描画する。
7. **inline style 厳守（RULES.md / Bug #19）**: 新規・任意値の Tailwind クラスは使わない。確認済みクラス（`bg-white border border-gray-200 rounded-lg p-5` 等）のみ流用し、それ以外は inline style か page-local `<style>`。

---

## File Structure

### 変更（modify）
| ファイル | 責務・変更内容 |
|---|---|
| `app/Support/Zeal/HacomonoCsvReader.php` | `readContent(string): array` を切り出し、`read($path)` は委譲（後方互換）。文字列入力を可能にする |
| `app/Http/Controllers/Admin/ZealMemberImportController.php` | preview/execute/loadCsv を Reader+Mapper 呼び出しへ置換。`makeMapper()`/`isDuplicate()` 追加。16列用 map 群・`template()`・`toCsvLine()` を撤去 |
| `resources/views/admin/zeal-member-import/index.blade.php` | 新フォーマット説明へ刷新。サンプルDLボタン撤去 |
| `resources/views/admin/zeal-member-import/preview.blade.php` | 区分/警告/除外/重複/エラーを一覧表示へ刷新（inline style） |
| `routes/web.php` | `admin.zeal.member-import.template` ルート削除（4→3 ルート） |
| `tests/Unit/Zeal/HacomonoCsvReaderTest.php` | `readContent()` の単体テスト 1 件追記 |

### 新規（create）
| ファイル | 責務 |
|---|---|
| `tests/Concerns/CreatesZealSchema.php` | テスト時に zeal 4 表を SQLite へ構築する trait（FK 省略・DDL 準拠の列） |
| `tests/Feature/Admin/ZealMemberImportControllerTest.php` | preview/execute/重複/形式違い/認可/index/route 削除の Feature テスト。77 列 CSV はテスト内で生成 |

### 触らない（do NOT touch）
`app/Support/Zeal/HacomonoMemberMapper.php` / `MappedMember.php` / `app/Console/Commands/ZealImportMembersCommand.php` / `tests/Unit/Zeal/HacomonoMemberMapperTest.php`（既存 Reader テストは追記のみ）。

---

## Task 1: `HacomonoCsvReader::readContent()` を切り出す

**Files:**
- Modify: `app/Support/Zeal/HacomonoCsvReader.php`
- Test: `tests/Unit/Zeal/HacomonoCsvReaderTest.php`

- [ ] **Step 1: 失敗するテストを追記**

`tests/Unit/Zeal/HacomonoCsvReaderTest.php` の既存メソッドの後（クラス内）に追記:

```php
    public function test_read_content_matches_read_from_path(): void
    {
        $path = __DIR__ . '/../../fixtures/zeal/reader_sample.csv';
        $fromPath   = HacomonoCsvReader::read($path);
        $fromString = HacomonoCsvReader::readContent(file_get_contents($path));

        // パス入力と文字列入力で同一結果
        $this->assertEquals($fromPath, $fromString);
        // 文字列入力でも BOM 除去・引用内改行が効く
        $this->assertSame('CL001', $fromString[0]['ID']);
        $this->assertStringContainsString('2行目', $fromString[0]['備考']);
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit --filter test_read_content_matches_read_from_path`
Expected: FAIL（`Call to undefined method ...::readContent()`）

- [ ] **Step 3: `readContent()` を実装し `read()` を委譲に変更**

`app/Support/Zeal/HacomonoCsvReader.php` の `read()` メソッド全体を以下で置換:

```php
    /**
     * パスから読み込み、readContent() に委譲する。
     *
     * @return array<int,array<string,string>>
     */
    public static function read(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("CSVを読み込めません: {$path}");
        }

        return self::readContent($content);
    }

    /**
     * hacomono形式CSV（文字列）を連想配列の配列に変換する。
     * - 文字コード自動判定（UTF-8 / SJIS-win / SJIS / EUC-JP）→ UTF-8 へ変換
     * - 先頭 BOM 除去
     * - 引用フィールド内の改行に対応（fgetcsv 使用）
     * - 各行はヘッダー名をキーにした配列。列数が足りない場合は空文字で補完。
     *
     * @return array<int,array<string,string>>
     */
    public static function readContent(string $content): array
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('CSV解析用の一時ストリームを開けませんでした');
        }
        fwrite($fh, $content);
        rewind($fh);

        // escape='' で RFC 4180 準拠のパース（メモ内のバックスラッシュを誤エスケープしない）
        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($header === false) {
            fclose($fh);
            return [];
        }
        // ヘッダーキー前後の空白を除去（' ID ' のような列名でも $row['ID'] で引けるように）
        $header = array_map('trim', $header);
        $colCount = count($header);

        $rows = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
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
```

- [ ] **Step 4: テストが通ることを確認（既存 Reader テストも green）**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Unit/Zeal/HacomonoCsvReaderTest.php`
Expected: PASS（2 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Support/Zeal/HacomonoCsvReader.php tests/Unit/Zeal/HacomonoCsvReaderTest.php
git commit -m "refactor(zeal): HacomonoCsvReaderにreadContent()を切り出し文字列入力に対応"
```

---

## Task 2: テスト基盤（zeal スキーマ構築 trait）

**Files:**
- Create: `tests/Concerns/CreatesZealSchema.php`
- Test: `tests/Feature/Admin/ZealMemberImportControllerTest.php`（このタスクではスキーマ健全性テストのみ。Task 3 以降で本体テストを追加）

- [ ] **Step 1: trait を作成**

`tests/Concerns/CreatesZealSchema.php`:

```php
<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * zeal_* テーブルは本番では raw SQL DDL（database/sql/create_zeal_tables.sql）で
 * 管理され Laravel マイグレーションに無い。テスト（SQLite in-memory）で
 * これらを使うため、DDL に準拠した最小スキーマを構築する。
 *
 * - FK 制約は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）。
 * - 列名・NULL 可否・型は create_zeal_tables.sql に合わせる。
 */
trait CreatesZealSchema
{
    protected function createZealSchema(): void
    {
        Schema::create('zeal_stores', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('address', 300)->nullable();
            $t->string('phone', 20)->nullable();
            $t->date('open_date')->nullable();
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->unsignedInteger('regular_price_excl');
            $t->unsignedInteger('campaign_price_excl')->nullable();
            $t->date('campaign_starts_on')->nullable();
            $t->date('campaign_ends_on')->nullable();
            $t->integer('max_concurrent_reservations')->nullable();
            $t->boolean('includes_personal')->default(false);
            $t->boolean('includes_semi_personal')->default(false);
            $t->integer('monthly_session_limit')->nullable();
            $t->boolean('is_pair_plan')->default(false);
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->integer('gym_inquiry_id')->nullable();
            $t->string('name', 100);
            $t->string('name_kana', 100)->nullable();
            $t->string('gender', 10)->nullable();
            $t->date('birthday')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('postal_code', 8)->nullable();
            $t->string('address', 300)->nullable();
            $t->date('joined_on');
            $t->date('withdrew_on')->nullable();
            $t->string('withdraw_reason', 50)->nullable();
            $t->text('withdraw_note')->nullable();
            $t->unsignedBigInteger('current_plan_id')->nullable();
            $t->unsignedBigInteger('trainer_id')->nullable();
            $t->unsignedBigInteger('pair_parent_member_id')->nullable();
            $t->string('acquisition_source', 30)->nullable();
            $t->string('purpose', 50)->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('zeal_member_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('plan_id');
            $t->date('period_start');
            $t->date('period_end')->nullable();
            $t->unsignedInteger('applied_price_excl');
            $t->boolean('is_campaign_applied')->default(false);
            $t->decimal('tax_rate_at_contract', 5, 2);
            $t->string('change_reason', 50)->nullable();
            $t->string('note', 200)->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
        });
    }
}
```

- [ ] **Step 2: スキーマ健全性テスト（失敗想定）を作成**

`tests/Feature/Admin/ZealMemberImportControllerTest.php`（新規・このタスクでは最小）:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\ZealMember;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesZealSchema;
use Tests\TestCase;

class ZealMemberImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createZealSchema();
    }

    public function test_zeal_schema_is_usable(): void
    {
        $store = ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        $plan  = ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        $member = ZealMember::create([
            'store_id' => $store->id, 'name' => '健全 太郎', 'joined_on' => '2025-10-17',
            'current_plan_id' => $plan->id, 'created_by' => 1, 'updated_by' => 1,
        ]);

        $this->assertDatabaseCount('zeal_members', 1);
        $this->assertSame('健全 太郎', $member->fresh()->name);
    }
}
```

- [ ] **Step 3: テスト実行で trait が機能することを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php`
Expected: PASS（1 test）。失敗する場合は trait の列定義（NOT NULL/型）を DDL と突き合わせて修正。

- [ ] **Step 4: コミット**

```bash
git add tests/Concerns/CreatesZealSchema.php tests/Feature/Admin/ZealMemberImportControllerTest.php
git commit -m "test(zeal): 会員CSVインポートFeatureテスト用のzealスキーマ構築traitを追加"
```

---

## Task 3: コントローラ本体を Reader+Mapper へ置換

**Files:**
- Modify: `app/Http/Controllers/Admin/ZealMemberImportController.php`
- Test: `tests/Feature/Admin/ZealMemberImportControllerTest.php`

> このタスクでは `preview()`/`execute()`/`loadCsv()` を新実装に置換し、`makeMapper()`/`isDuplicate()` を追加、16列用の `genderMap`/`acquisitionMap`/`purposeMap`/`normalizeDate()` と未使用 import を撤去する。
> **`columnMap` / `toCsvLine()` / `template()` は Task 5 まで残置**（template ルート/ビューがまだ参照するため）。
> preview ビューの刷新は Task 4。このタスクのテストは preview Blade を描画しない経路（execute・形式違い・認可）のみ検証する。

- [ ] **Step 1: テスト用ヘルパー（CSV 生成・認可ユーザー）を Feature テストに追加**

`tests/Feature/Admin/ZealMemberImportControllerTest.php` のクラス先頭（`use` 群の直後）に 77 列ヘッダー定数とヘルパーを追加:

```php
    /** 新フォーマットの 77 列ヘッダー（サンプル CSV 準拠。次回課金予定日は仕様通り重複あり） */
    private const HEADERS = [
        'ID','状態','定期購入','独自会員ID','メールアドレス','名前','名前カナ','見出し','電話番号','郵便番号',
        '住所','身長','性別','生年月日','年齢','カスタム1','カスタム2','カスタム3','カスタム4','カスタム5',
        'カスタム6','カスタム7','カスタム8','カスタム9','カスタム10','緊急連絡先(続柄)','緊急連絡先(名前)','緊急連絡先(電話番号)','初回セッション日','体験利用日',
        '入会日','利用開始日','コース変更日(予約ルール)','コース変更日(課金設定)','退会予定日','退会日','システム外予約数','システム外売上','紹介コード','tracking_code',
        '顧客内部カルテ','顧客共有ノート','コースマスタ連動','次回課金予定日','チケットルール','残チケット数','残チケット数(来月)','残チケット数(再来月)','pay_customer_id','残ポイント数',
        '合計金額(初回)','合計金額(2回目以降)','決済エラー','課金済み月','次回課金予定日','作成日','金融機関番号','金融機関名','支店番号','支店名',
        '口座種別','口座番号','口座名義','店舗 ID','店舗 名前','支払手段','次回課金予定日','コース ID','コース 名前','コース 名前（内部）',
        'コース 合計金額(初回)','コース 合計金額(2回目以降)','変更後コース ID','変更後コース 名前','変更後コース 名前（内部）','変更後コース 合計金額(初回)','変更後コース 合計金額(2回目以降)',
    ];

    /** password.change を通過する経営層ユーザー */
    private function executive(): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プラン・店舗マスタを seed（fixture が参照する 2 プラン + 既定店舗） */
    private function seedMasters(): void
    {
        ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        ZealPlan::create(['name' => 'パーソナル&セミパーソナル月4回', 'regular_price_excl' => 13000]);
    }

    /** 使用列のみ持つ 1 行（他列は空）。$over で上書き */
    private function baseRow(array $over = []): array
    {
        return array_merge([
            'ID' => 'CL00000000', '状態' => '会員', '定期購入' => 'TRUE',
            '名前' => '', '名前カナ' => '', '性別' => '男性', '生年月日' => '1990/1/2',
            'カスタム2' => '（新）セミパーソナル通い放題', '入会日' => '2025/10/17',
            '退会予定日' => '', '退会日' => '', '紹介コード' => '', '顧客内部カルテ' => '',
            '残チケット数' => '0', '合計金額(2回目以降)' => '9702',
            '店舗 名前' => 'ZEAL BOXING FITNESS 松山市駅前店',
            'コース 名前' => '（新）セミパーソナル通い放題', 'コース 合計金額(2回目以降)' => '10780',
            '変更後コース 名前' => '',
        ], $over);
    }

    /** 5 区分 + ビジター(除外) + 氏名空(エラー) の 7 行を持つ標準 fixture */
    private function fixtureRows(): array
    {
        return [
            // 1. 在籍(active): 9702税込 → 8820税抜
            $this->baseRow(['ID' => 'CL001', '名前' => '在籍 太郎', '名前カナ' => 'ザイセキ タロウ', '入会日' => '2025/10/17', '合計金額(2回目以降)' => '9702']),
            // 2. 退会済(withdrawn): 停止中 + 退会日。プラン定価 9800、period_end 設定
            $this->baseRow(['ID' => 'CL002', '状態' => '停止中', '名前' => '退会 花子', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '', '合計金額(2回目以降)' => '0', '退会日' => '2026/6/1', '定期購入' => 'FALSE', '入会日' => '2024/4/1']),
            // 3. 休会(dormant): コース名前=休会プラン。1100税込 → 1000税抜
            $this->baseRow(['ID' => 'CL003', '名前' => '休会 次郎', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '休会プラン', '合計金額(2回目以降)' => '1100', '入会日' => '2025/1/10']),
            // 4. チケット(ticket): プラン未解決 → 会員のみ・契約なし
            $this->baseRow(['ID' => 'CL004', '名前' => '券 三郎', '定期購入' => 'FALSE', 'カスタム2' => '', 'コース 名前' => 'チケット会員', '合計金額(2回目以降)' => '0', '残チケット数' => '4', '入会日' => '2025/3/3']),
            // 5. 定期OFF(inactive_zero): 定期FALSE + 実請求0 → プラン定価 13000
            $this->baseRow(['ID' => 'CL005', '名前' => '休眠 四郎', '定期購入' => 'FALSE', 'カスタム2' => 'パーソナル&セミパーソナル月4回（松山市駅前）', 'コース 名前' => 'パーソナル&セミパーソナル月4回（松山市駅前）', '合計金額(2回目以降)' => '0', '入会日' => '2025/5/5']),
            // 6. 除外(ビジター)
            $this->baseRow(['ID' => 'CL006', '状態' => 'ビジター', '名前' => '見学 五郎', '入会日' => '2026/2/2']),
            // 7. エラー(氏名空)
            $this->baseRow(['ID' => 'CL007', '名前' => '', '入会日' => '2025/7/7']),
        ];
    }

    /** 行配列 → 77 列 CSV 文字列 */
    private function csvContent(array $rows): string
    {
        $lines = [implode(',', self::HEADERS)];
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::HEADERS as $h) {
                $cells[] = '"' . str_replace('"', '""', (string) ($row[$h] ?? '')) . '"';
            }
            $lines[] = implode(',', $cells);
        }
        return implode("\n", $lines);
    }

    /** CSV 文字列を mimes:csv を通る UploadedFile にする */
    private function uploadFrom(string $content): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zealcsv') . '.csv';
        file_put_contents($path, $content);
        return new \Illuminate\Http\UploadedFile($path, 'members.csv', 'text/csv', null, true);
    }
```

- [ ] **Step 2: 失敗するテスト（execute / 形式違い / 認可）を追加**

同ファイルのクラス内に追加:

```php
    public function test_execute_imports_each_kind_and_excludes_visitor(): void
    {
        $this->seedMasters();
        $content = $this->csvContent($this->fixtureRows());

        $response = $this->actingAs($this->executive())
            ->from(route('admin.zeal.member-import'))
            ->post(route('admin.zeal.member-import.execute'), [
                'confirmed' => '1',
                'csv_data'  => base64_encode($content),
            ]);

        $response->assertRedirect(route('admin.zeal.member-import'));
        $response->assertSessionHas('success');

        // 5 区分が会員化（ビジター除外・エラー行スキップ）
        $this->assertDatabaseCount('zeal_members', 5);
        // 契約はチケットを除く 4 件
        $this->assertDatabaseCount('zeal_member_contracts', 4);

        // 退会済: withdrew_on と契約 period_end が入る
        $this->assertDatabaseHas('zeal_members', ['name' => '退会 花子', 'withdrew_on' => '2026-06-01']);
        // チケット: current_plan_id は null（契約なし）
        $this->assertDatabaseHas('zeal_members', ['name' => '券 三郎', 'current_plan_id' => null]);
    }

    public function test_execute_skips_existing_duplicate(): void
    {
        $this->seedMasters();
        // 在籍 太郎(2025-10-17) を事前作成 → fixture の同名・同入会日はスキップされる
        ZealMember::create([
            'store_id' => ZealStore::first()->id, 'name' => '在籍 太郎',
            'joined_on' => '2025-10-17', 'created_by' => 1, 'updated_by' => 1,
        ]);

        $content = $this->csvContent($this->fixtureRows());
        $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.execute'), [
                'confirmed' => '1', 'csv_data' => base64_encode($content),
            ])->assertRedirect(route('admin.zeal.member-import'));

        // 事前 1 + 取込 4（在籍は重複スキップ）= 5
        $this->assertDatabaseCount('zeal_members', 5);
        // 取込 4 のうちチケットを除く 3 件が契約
        $this->assertDatabaseCount('zeal_member_contracts', 3);
    }

    public function test_preview_rejects_wrong_format(): void
    {
        $this->seedMasters();
        $wrong = "状態,入会日\n会員,2025/10/17\n"; // 名前 列が無い

        $this->actingAs($this->executive())
            ->from(route('admin.zeal.member-import'))
            ->post(route('admin.zeal.member-import.preview'), [
                'confirmed' => '1', 'csv_data' => base64_encode($wrong),
            ])
            ->assertRedirect(route('admin.zeal.member-import'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('zeal_members', 0);
    }

    public function test_non_executive_is_forbidden(): void
    {
        $manager = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Manager->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($manager)
            ->get(route('admin.zeal.member-import'))
            ->assertForbidden();
    }
```

- [ ] **Step 3: テストが失敗することを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php`
Expected: `test_execute_*` と `test_preview_rejects_wrong_format` が FAIL（旧コントローラは 16 列前提で execute が 0 件取込・形式違い判定が無い）。`test_non_executive_is_forbidden` は既に PASS の可能性あり。

- [ ] **Step 4: コントローラの import と先頭を置換**

`app/Http/Controllers/Admin/ZealMemberImportController.php` の冒頭 `use` 群（`namespace` 直後〜class 定義前）を以下へ置換:

```php
use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Support\Settings;
use App\Support\Zeal\HacomonoCsvReader;
use App\Support\Zeal\HacomonoMemberMapper;
use App\Support\Zeal\MappedMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
```

（`ZealAcquisitionSource` / `ZealContractChangeReason` / `ZealGender` / `ZealPurpose` / `ZealTrainer` の import を削除）

- [ ] **Step 5: `genderMap` / `acquisitionMap` / `purposeMap` を削除**

`private array $genderMap = [...]` `private array $acquisitionMap = [...]` `private array $purposeMap = [...]` の 3 ブロックを削除する。
**`private array $columnMap = [...]` は残す**（`template()` がまだ使用。Task 5 で削除）。

- [ ] **Step 6: `preview()` を置換**

既存 `preview(Request $request)` メソッド全体を以下へ置換:

```php
    /**
     * CSV をパース → 区分判定 → プレビュー表示
     * Route: POST /admin/zeal/member-import/preview
     */
    public function preview(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        $mapper = $this->makeMapper();
        if ($mapper instanceof \Illuminate\Http\RedirectResponse) {
            return $mapper;
        }

        /** @var MappedMember[] $toImport */ $toImport = [];
        /** @var MappedMember[] $skipped */  $skipped  = [];
        /** @var MappedMember[] $errored */  $errored  = [];
        $excluded = []; // ['name' => , 'status' => ] ビジター等（取込対象外）

        foreach ($rows as $row) {
            if (!HacomonoMemberMapper::isInScope($row)) {
                $excluded[] = ['name' => trim($row['名前'] ?? ''), 'status' => trim($row['状態'] ?? '')];
                continue;
            }
            $m = $mapper->map($row);
            if ($m->hasErrors()) {
                $errored[] = $m;
                continue;
            }
            if ($this->isDuplicate($m)) {
                $skipped[] = $m;
                continue;
            }
            $toImport[] = $m;
        }

        return view('admin.zeal-member-import.preview', compact(
            'toImport', 'skipped', 'errored', 'excluded', 'content'
        ));
    }
```

- [ ] **Step 7: `execute()` を置換**

既存 `execute(Request $request)` メソッド全体を以下へ置換:

```php
    /**
     * プレビュー確認後の実際の取込処理
     * Route: POST /admin/zeal/member-import/execute
     *
     * エラー行はスキップして取込を続行する（Web は部分取込許容。CLI の全件中断とは方針を変える）。
     */
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows] = $result;

        $mapper = $this->makeMapper();
        if ($mapper instanceof \Illuminate\Http\RedirectResponse) {
            return $mapper;
        }

        $taxRate = Settings::taxRate();
        $actorId = auth()->id();

        $imported = 0;
        $skipped  = 0;
        $errored  = 0;
        $excluded = 0;

        DB::transaction(function () use (
            $rows, $mapper, $taxRate, $actorId,
            &$imported, &$skipped, &$errored, &$excluded
        ) {
            foreach ($rows as $row) {
                if (!HacomonoMemberMapper::isInScope($row)) {
                    $excluded++;
                    continue;
                }
                $m = $mapper->map($row);
                if ($m->hasErrors()) {
                    $errored++;
                    continue;
                }
                if ($this->isDuplicate($m)) {
                    $skipped++;
                    continue;
                }

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

                $imported++;
            }
        });

        return redirect()
            ->route('admin.zeal.member-import')
            ->with('success', "インポート完了: 登録 {$imported}件 / スキップ {$skipped}件 / エラー {$errored}件 / 除外 {$excluded}件");
    }

    /**
     * プラン/店舗マスタから Mapper を生成する。有効店舗が無ければリダイレクトを返す。
     *
     * @return HacomonoMemberMapper|\Illuminate\Http\RedirectResponse
     */
    private function makeMapper()
    {
        $planIdMap    = ZealPlan::pluck('id', 'name')->toArray();
        $planPriceMap = ZealPlan::pluck('regular_price_excl', 'name')->toArray();
        $storeIdMap   = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
        if (!$defaultStore) {
            return back()->with('error', '有効な店舗が登録されていません。先に店舗マスタを登録してください。');
        }

        return new HacomonoMemberMapper(
            $planIdMap,
            $planPriceMap,
            $storeIdMap,
            $defaultStore->id,
            Settings::taxRate()
        );
    }

    /** 氏名 + 入会日で既存会員と重複するか */
    private function isDuplicate(MappedMember $m): bool
    {
        return ZealMember::where('name', $m->displayName)
            ->where('joined_on', $m->memberAttributes['joined_on'])
            ->exists();
    }
```

- [ ] **Step 8: `loadCsv()` を置換し、`normalizeDate()` を削除**

既存 `loadCsv(Request $request)` メソッド全体を以下へ置換:

```php
    /**
     * CSV を読み込み、行データの配列と元 CSV 文字列を返す。
     * confirmed=1（プレビュー確認後）は base64 から復元する。
     *
     * @return array{0: array<int,array<string,string>>, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function loadCsv(Request $request)
    {
        if ($request->boolean('confirmed')) {
            $content = base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $content = file_get_contents($request->file('csv_file')->getRealPath());

            // 保存する base64 を常に UTF-8 へ揃える（confirmed 経路の再パースを安定させる）
            $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        }

        // 引用フィールド内改行（顧客内部カルテの複数行）に対応するため readContent を使う
        $rows = HacomonoCsvReader::readContent($content);
        if (count($rows) === 0) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        // 新フォーマットの主要列が無ければ「形式違い」として弾く
        $first = $rows[0];
        foreach (['名前', '入会日', '状態'] as $required) {
            if (!array_key_exists($required, $first)) {
                return back()->with('error', "CSVの形式が異なります（必須列「{$required}」が見つかりません）。会員管理システムからエクスポートしたCSVをアップロードしてください。");
            }
        }

        return [$rows, $content];
    }
```

続けて、既存の `private function normalizeDate(string $value): ?string { ... }` メソッド全体を削除する。
**`toCsvLine()` と `template()` は残す**（Task 5 で削除）。

- [ ] **Step 9: テストを実行（execute 系が green、preview 描画はまだ）**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php`
Expected: `test_execute_imports_each_kind_and_excludes_visitor` / `test_execute_skips_existing_duplicate` / `test_preview_rejects_wrong_format` / `test_non_executive_is_forbidden` / `test_zeal_schema_is_usable` が PASS。
（preview の描画テストは Task 4 で追加するためまだ無い。`php -l` で構文確認: `php -l app/Http/Controllers/Admin/ZealMemberImportController.php`）

- [ ] **Step 10: コミット**

```bash
git add app/Http/Controllers/Admin/ZealMemberImportController.php tests/Feature/Admin/ZealMemberImportControllerTest.php
git commit -m "feat(zeal): 会員CSV取込のpreview/executeを新77列形式(Reader+Mapper)へ置換"
```

---

## Task 4: プレビュー画面を新フォーマットへ刷新

**Files:**
- Modify: `resources/views/admin/zeal-member-import/preview.blade.php`
- Test: `tests/Feature/Admin/ZealMemberImportControllerTest.php`

- [ ] **Step 1: 失敗する preview 描画テストを追加**

`tests/Feature/Admin/ZealMemberImportControllerTest.php` のクラス内に追加:

```php
    public function test_preview_classifies_all_kinds(): void
    {
        $this->seedMasters();
        $content = $this->csvContent($this->fixtureRows());

        $response = $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.preview'), [
                'csv_file' => $this->uploadFrom($content),
            ]);

        $response->assertOk(); // 新 Blade が描画できること（Bug #26 型の検出も兼ねる）

        // 区分ごとの件数（view data で厳密検証）
        $this->assertCount(5, $response->viewData('toImport'));
        $this->assertCount(1, $response->viewData('errored'));
        $this->assertCount(1, $response->viewData('excluded'));
        $this->assertCount(0, $response->viewData('skipped'));

        // 画面に区分ラベルと氏名が出ること
        $response->assertSee('在籍');
        $response->assertSee('退会済');
        $response->assertSee('休会');
        $response->assertSee('チケット');
        $response->assertSee('定期OFF');
        $response->assertSee('在籍 太郎');
        $response->assertSee('券 三郎');
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit --filter test_preview_classifies_all_kinds`
Expected: FAIL（旧 preview.blade は `$validRows` 等を参照し `Undefined variable` で 500 → assertOk 失敗）

- [ ] **Step 3: `preview.blade.php` 全体を置換**

`resources/views/admin/zeal-member-import/preview.blade.php` を以下で全置換:

```blade
@extends('layouts.app')

@section('title', 'ZEAL 会員CSVインポート — プレビュー')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.zeal.member-import') }}" class="hover:text-emerald-600 transition-colors">会員CSVインポート</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">プレビュー</span>
@endsection

@section('content')

@php
    use App\Support\Zeal\HacomonoMemberMapper as HM;
    $kindLabel = [
        HM::KIND_ACTIVE        => '在籍',
        HM::KIND_WITHDRAWN     => '退会済',
        HM::KIND_DORMANT       => '休会',
        HM::KIND_TICKET        => 'チケット',
        HM::KIND_INACTIVE_ZERO => '定期OFF',
    ];
    // 区分バッジ色（inline style）
    $kindStyle = [
        HM::KIND_ACTIVE        => 'background:#d1fae5;color:#065f46;',
        HM::KIND_WITHDRAWN     => 'background:#fee2e2;color:#991b1b;',
        HM::KIND_DORMANT       => 'background:#fef3c7;color:#92400e;',
        HM::KIND_TICKET        => 'background:#e0e7ff;color:#3730a3;',
        HM::KIND_INACTIVE_ZERO => 'background:#f3f4f6;color:#374151;',
    ];
@endphp

<style>
    .preview-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 880px; }
    .preview-table thead th { background: #f9fafb; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; color: #374151; white-space: nowrap; }
    .preview-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: top; }
    .preview-table tbody tr:last-child td { border-bottom: none; }
    .kind-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">インポート プレビュー</h1>
    <a href="{{ route('admin.zeal.member-import') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        やり直す
    </a>
</div>

{{-- サマリーバッジ --}}
<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px;">
        <span style="font-size: 22px; font-weight: 700; color: #065f46;">{{ count($toImport) }}</span>
        <span style="font-size: 13px; color: #065f46; font-weight: 600;">件 登録予定</span>
    </div>
    @if(count($skipped) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #6b7280;">{{ count($skipped) }}</span>
            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">件 スキップ（既存）</span>
        </div>
    @endif
    @if(count($errored) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #991b1b;">{{ count($errored) }}</span>
            <span style="font-size: 13px; color: #991b1b; font-weight: 600;">件 エラー（取込しない）</span>
        </div>
    @endif
    @if(count($excluded) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #6b7280;">{{ count($excluded) }}</span>
            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">件 除外（対象外）</span>
        </div>
    @endif
</div>

{{-- エラー行（取込しない） --}}
@if(count($errored) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px; border-color:#fecaca;">
        <div style="font-size: 14px; font-weight: 700; color: #991b1b; margin-bottom: 12px;">⚠️ エラー行（取込対象外）</div>
        @foreach($errored as $m)
            <div style="padding: 8px 12px; background: #fef2f2; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                <span style="font-weight: 700; color: #991b1b;">{{ $m->sourceId }} {{ $m->displayName ?: '（氏名なし）' }}</span>
                <ul style="margin: 4px 0 0; padding-left: 18px; color: #b91c1c;">
                    @foreach($m->errors as $msg)
                        <li>{{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif

{{-- スキップ（既存重複） --}}
@if(count($skipped) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #6b7280; margin-bottom: 12px;">スキップ行（同名・同入会日が既存）</div>
        @foreach($skipped as $m)
            <div style="padding: 7px 12px; background: #f9fafb; border-radius: 6px; margin-bottom: 6px; font-size: 13px; color: #6b7280;">
                {{ $m->sourceId }} {{ $m->displayName }} — 入会日 {{ $m->memberAttributes['joined_on'] }}
            </div>
        @endforeach
    </div>
@endif

{{-- 除外（ビジター等・取込対象外） --}}
@if(count($excluded) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #6b7280; margin-bottom: 12px;">除外（在籍/停止中 以外。ビジター等）</div>
        @foreach($excluded as $ex)
            <div style="padding: 7px 12px; background: #f9fafb; border-radius: 6px; margin-bottom: 6px; font-size: 13px; color: #6b7280;">
                {{ $ex['name'] ?: '（氏名なし）' }} — 状態「{{ $ex['status'] ?: '空' }}」
            </div>
        @endforeach
    </div>
@endif

{{-- 登録予定の一覧 --}}
@if(count($toImport) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 12px;">登録予定 {{ count($toImport) }}件</div>
        <div style="overflow-x: auto;">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>元ID</th>
                        <th>氏名</th>
                        <th>状態</th>
                        <th>区分</th>
                        <th>プラン</th>
                        <th>月会費（税抜）</th>
                        <th>退会(予定)日</th>
                        <th>警告</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($toImport as $m)
                        <tr>
                            <td style="color:#9ca3af; white-space:nowrap;">{{ $m->sourceId }}</td>
                            <td style="font-weight:600; white-space:nowrap;">{{ $m->displayName }}</td>
                            <td style="color:#6b7280;">{{ $m->status }}</td>
                            <td><span class="kind-badge" style="{{ $kindStyle[$m->kind] ?? 'background:#f3f4f6;color:#374151;' }}">{{ $kindLabel[$m->kind] ?? $m->kind }}</span></td>
                            <td style="font-weight:600; color:#047857;">{{ $m->planName ?? '（未対応:'.$m->rawPlan.'）' }}</td>
                            <td style="text-align:right; white-space:nowrap;">{{ $m->appliedPriceExcl !== null ? number_format($m->appliedPriceExcl).'円' : '—' }}</td>
                            <td style="white-space:nowrap;">{{ $m->withdrewOn ?? $m->scheduledOn ?? '—' }}</td>
                            <td style="color:#92400e; font-size:11px;">{{ implode(' / ', $m->warnings) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div style="padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
        登録対象の行がありません。CSVを確認して再度アップロードしてください。
    </div>
@endif

{{-- 実行フォーム --}}
@if(count($toImport) > 0)
    <form method="POST" action="{{ route('admin.zeal.member-import.execute') }}">
        @csrf
        <input type="hidden" name="confirmed" value="1">
        <input type="hidden" name="csv_data" value="{{ base64_encode($content) }}">

        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="{{ route('admin.zeal.member-import') }}"
               style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
                キャンセル
            </a>
            <button type="submit"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                {{ count($toImport) }}件をインポート実行する
            </button>
        </div>
    </form>
@else
    <a href="{{ route('admin.zeal.member-import') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        やり直す
    </a>
@endif

@endsection
```

- [ ] **Step 4: テストが通ることを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php`
Expected: 全 PASS（`test_preview_classifies_all_kinds` 含む）

- [ ] **Step 5: コミット**

```bash
git add resources/views/admin/zeal-member-import/preview.blade.php tests/Feature/Admin/ZealMemberImportControllerTest.php
git commit -m "feat(zeal): 会員CSV取込プレビューを区分(在籍/退会済/休会/チケット/定期OFF)表示へ刷新"
```

---

## Task 5: 取込トップ画面を新フォーマット説明へ刷新 ＋ テンプレート機能を撤去

**Files:**
- Modify: `resources/views/admin/zeal-member-import/index.blade.php`
- Modify: `app/Http/Controllers/Admin/ZealMemberImportController.php`（`template()` / `toCsvLine()` / `columnMap` 削除）
- Modify: `routes/web.php`（template ルート削除）
- Test: `tests/Feature/Admin/ZealMemberImportControllerTest.php`

- [ ] **Step 1: 失敗するテストを追加**

`tests/Feature/Admin/ZealMemberImportControllerTest.php` のクラス内に追加:

```php
    public function test_index_shows_new_format_guidance_without_template_link(): void
    {
        $response = $this->actingAs($this->executive())
            ->get(route('admin.zeal.member-import'));

        $response->assertOk();
        $response->assertSee('会員管理システム');     // 新フォーマット説明
        $response->assertDontSee('サンプルCSVをダウンロード'); // 旧テンプレDLは撤去
    }

    public function test_template_route_is_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.zeal.member-import.template'));

        $this->actingAs($this->executive())
            ->get('/admin/zeal/member-import/template')
            ->assertNotFound();
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit --filter "test_index_shows_new_format_guidance_without_template_link|test_template_route_is_removed"`
Expected: FAIL（旧 index に「サンプルCSVをダウンロード」が存在・template ルートが存在）

- [ ] **Step 3: `index.blade.php` 全体を置換**

`resources/views/admin/zeal-member-import/index.blade.php` を以下で全置換:

```blade
@extends('layouts.app')

@section('title', 'ZEAL 会員CSVインポート')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">会員CSVインポート</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">ZEAL 会員 CSVインポート</h1>
</div>

@if($errors->any())
    <div style="padding: 12px 16px; margin-bottom: 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
        <div style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 6px;">入力エラー</div>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #991b1b;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-lg p-5">

    {{-- 説明 --}}
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
        <div style="font-weight: 700; font-size: 14px; color: #166534; margin-bottom: 8px;">会員管理システムのエクスポートCSVを取り込みます</div>
        <ul style="font-size: 13px; color: #15803d; margin: 0; padding-left: 18px; line-height: 2;">
            <li>会員管理システムからエクスポートした会員CSVをそのままアップロードします（編集不要）</li>
            <li>在籍・退会済・休会・チケット・定期OFF を自動判定して取り込みます</li>
            <li>ビジター等（在籍/停止中 以外）は取込対象外として自動的に除外します</li>
            <li>プラン名は登録済みプランへ自動マッピングします（解決できない行はエラーとして取込しません）</li>
            <li>同名・同入会日の会員が既にDBに存在する場合はスキップします</li>
            <li>取込時に契約レコードも自動作成します（チケット会員は契約なし／退会済は契約クローズ）</li>
            <li>文字コードは UTF-8 / Shift_JIS どちらでも対応しています</li>
        </ul>
    </div>

    {{-- 区分の説明 --}}
    <div style="margin-bottom: 24px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 10px;">取込時の区分</div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">区分</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">判定条件</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">月会費（税抜）・契約</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                    $kinds = [
                        ['在籍',    '状態=会員 で通常課金',                       '実請求額から税抜換算。契約を作成（継続中）'],
                        ['退会済',  '状態=停止中 または 退会日あり',               'プラン定価（税抜）。契約は退会日でクローズ'],
                        ['休会',    'コース名=休会プラン',                         '実休会費（税抜）。契約は継続中'],
                        ['チケット','プラン未対応（チケット会員等）',               '会員のみ作成・契約なし'],
                        ['定期OFF', '定期購入=FALSE かつ 実請求0',                 'プラン定価（税抜）。契約を作成'],
                    ];
                    @endphp
                    @foreach($kinds as [$kind, $cond, $note])
                        <tr>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; font-weight: 700;">{{ $kind }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $cond }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $note }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ファイルアップロードフォーム --}}
    <form method="POST" action="{{ route('admin.zeal.member-import.preview') }}" enctype="multipart/form-data">
        @csrf

        <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                CSVファイルを選択 <span style="color: #dc2626;">*</span>
            </label>
            <input type="file" name="csv_file" accept=".csv,.txt" required
                   style="display: block; width: 100%; max-width: 520px; padding: 8px 12px; font-size: 13px; color: #374151; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; box-sizing: border-box;">
            <div style="font-size: 11px; color: #9ca3af; margin-top: 6px;">
                対応形式: CSV（UTF-8 / Shift_JIS）/ 最大 10MB
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <button type="submit"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 24px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                プレビューを確認する
            </button>
        </div>

    </form>

</div>

@endsection
```

- [ ] **Step 4: コントローラから `template()` / `toCsvLine()` / `columnMap` を削除**

`app/Http/Controllers/Admin/ZealMemberImportController.php` から以下を削除:
- `private array $columnMap = [ ... ];`（フィールド全体）
- `public function template() { ... }`（「テンプレート CSV ダウンロード」セクションのメソッド全体。直前のコメントブロックも）
- `private function toCsvLine(array $fields): string { ... }`（メソッド全体）

削除後、コントローラに残るのは: `index()` / `preview()` / `execute()` / `makeMapper()` / `isDuplicate()` / `loadCsv()`。

- [ ] **Step 5: `routes/web.php` から template ルートを削除**

`routes/web.php` の以下 2 行（コメント `// ZEAL 会員 CSV インポート（4ルート）` 直下の template GET）を削除:

```php
        Route::get('/zeal/member-import/template', [\App\Http\Controllers\Admin\ZealMemberImportController::class, 'template'])
            ->name('admin.zeal.member-import.template');
```

併せて直上コメントを `// ZEAL 会員 CSV インポート（3ルート）` に修正する。

- [ ] **Step 6: 構文チェックとテスト**

Run:
```bash
cd /Users/masanori/site/manage
php -l app/Http/Controllers/Admin/ZealMemberImportController.php
php artisan route:clear
vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php
```
Expected: lint OK / 全テスト PASS（`test_index_*`・`test_template_route_is_removed` 含む）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/Admin/ZealMemberImportController.php resources/views/admin/zeal-member-import/index.blade.php routes/web.php tests/Feature/Admin/ZealMemberImportControllerTest.php
git commit -m "feat(zeal): 会員CSV取込トップを新形式説明へ刷新し旧16列テンプレDL機能を撤去"
```

---

## Task 6: 最終検証 ＋ 本番反映準備

**Files:** なし（検証のみ）

- [ ] **Step 1: 全テストスイートを green で確認（main repo・dev 依存込み）**

```bash
cd /Users/masanori/site/manage
composer install            # dev 依存（phpunit）を入れる ※既に入っていればスキップ
vendor/bin/phpunit
```
Expected: 既存テスト含め全 PASS（Zeal Unit 25 + Reader 2 + 新 Feature 7 など）。

- [ ] **Step 2: Blade コンパイル検証（Bug #26 — `view:cache` 成功で満足しない）**

```bash
cd /Users/masanori/site/manage
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が 1 つも出ない。

- [ ] **Step 3: 実データ（本物の 77 列 CSV）でローカル preview を目視確認**

サンプル: `~/Desktop/clients_csv_JT9011396252.csv`（77 列・105 行・個人情報削除済）。
ローカル（`/admin/zeal/member-import`）へアップロードし、区分・除外・エラー・重複の件数が妥当か、月会費（税抜）が崩れていないかを目視。
※ 空ローカルで素通りする本番500（Bug #22/#25/#26）回避のため、この実データ検証は必須。
※ サンプルは氏名削除済のため「氏名空」エラーが多発する想定。件数の整合（在籍/停止中の合計 ≒ 取込対象）と画面が落ちないことを確認する。

- [ ] **Step 4: dev 依存を本番同等へ戻す**

```bash
cd /Users/masanori/site/manage
composer install --no-dev
```

- [ ] **Step 5: 本番反映（ユーザー承認後）**

1. 既に main repo (`/Users/masanori/site/manage`) の `13.x` 上で作業・コミット済みであることを確認（worktree 運用の場合は `git checkout 13.x && git merge --ff-only <branch>`）。
2. **新規 PHP クラスは無い**（trait はテスト専用で本番 rsync 除外）ため `composer dump-autoload` は不要。
3. `./deploy.sh`（rsync + 本番 config:cache / route:cache / view:cache 再生成）。
4. origin/13.x への push は **ユーザー明示指示時のみ**。
5. （任意）本番 `/admin/zeal/member-import` を Playwright で目視確認。

---

## Self-Review（プラン作成者チェック済み）

- **Spec coverage（設計書 §3〜§6）:**
  - D1 置換: 旧テンプレ/サンプルDL/template ルートを撤去（Task 5）✓
  - D2 名簿まるごと・5 区分・ビジター除外: Mapper 流用 + preview/execute（Task 3）✓
  - D3 氏名+入会日の重複判定: `isDuplicate()`（Task 3）✓
  - D4 文言中立化（Hacomono クラスは温存）: index/preview の文言（Task 4/5）✓
  - readContent 切り出し（§5.1）: Task 1 ✓
  - 引用内改行対応（顧客内部カルテ複数行）: `readContent()` 経由（Task 3 loadCsv）✓
  - エラー行は取込まずスキップ・部分取込（§5.3）: execute（Task 3）✓
  - テスト（§6・Reader/Controller）: Task 1 / Task 2〜5 ✓（fixture はテスト内生成へ変更＝静的 77 列ファイルの手作りミスを避けるため）
  - Bug #26 検証（§7）: Task 6 Step 2 ✓
- **Placeholder scan:** TODO/「適宜」等の曖昧表現なし。各 Step に実コード・実コマンドを記載 ✓
- **Type consistency:** controller→view 変数（`toImport`/`skipped`/`errored`/`excluded`/`content`）が preview.blade と一致。`HacomonoMemberMapper::isInScope`/`map`/`KIND_*`、`MappedMember` の public プロパティ（`sourceId`/`displayName`/`status`/`kind`/`planName`/`rawPlan`/`appliedPriceExcl`/`withdrewOn`/`scheduledOn`/`warnings`/`errors`/`memberAttributes`/`contractAttributes`）は実コードと一致 ✓

## 補足・既知のリスク（設計書 §9 より）
- サンプルCSVは個人情報削除済のため本番の実列構成が微妙に異なる可能性 → Task 6 Step 3 の実データ確認で吸収。
- 氏名未出力運用だと氏名重複判定が破綻（D5 前提）→ 実 CSV で要確認。
- テスト用 `CreatesZealSchema` は DDL の二重定義（テスト専用）。本番 DDL（`create_zeal_tables.sql`）変更時は trait も追従が必要（コメントに明記済）。
