# 賃貸マンション CSV 取込のテスト整備 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 賃貸マンション CSV 取込（`MansionImportController` 6 経路）の振る舞いを回帰テストで固定し、その過程で両取込コントローラが逐語コピーしている共通ヘルパーを 1 本化して `normalizeDate` の欠陥を直す。

**Architecture:** 完全一致の共通ヘルパー 4 本を `app/Support/` の純粋な部品（HTTP を知らない）へ移す。移動は振る舞い不変のコミット、`CsvDate` の修正は別コミットに分ける。その上で 6 経路を「プレビュー → 確定」の往復 Feature テストで固定し、テスト用スキーマに本番 DDL の UNIQUE 制約を足して測定器の穴を塞ぐ。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit 11 / SQLite in-memory

**設計書:** `docs/superpowers/specs/2026-08-18-mansion-csv-import-tests-design.md`

**作業場所:** worktree `.claude/worktrees/tenant-area-survey`（branch `mansion-import-tests`）
テストの実行は `./vendor/bin/phpunit`（main repo は `--no-dev` で phpunit が無い）

---

## 前提（この計画を書いた時点の状態）

- `13.x` = `b74128ab`、830 tests green
- **CSV 取込プレビューの 500 は既に直っている**（Bug #53、`ce528b85` + `7a3b2c5e`、本番反映済み）
- `tests/Feature/Admin/ImportPreviewRenderTest.php` が 12 経路のプレビュー描画を全件分類で守っている
  → **Task 4 の切り出しはこのテストが安全網になる。壊したら即座に赤くなる**
- `tests/Concerns/CreatesSurveyQuestionSchema.php` は Bug #53 の副産物として追加済み

### 設計書との差分

**設計書 §4.4 の「`MansionImportTemplateTest`（テンプレート往復ラチェット）」は、この計画に
タスクが無い。既に `ImportPreviewRenderTest` として実装済みだから**（Bug #53 の対応で、
設計書に書いたものより広い範囲＝賃貸マンション 6 経路だけでなく顧客・テナントも含む
12 経路を、同じ「全件分類」の形で守っている）。重複して作らないこと。

同テストが押さえているもの:

- 取込の入口の全件列挙（コントローラのメソッド名 `execute{X}` ↔ `download{X}Template` で導出）
- その画面自身のテンプレート CSV を落として上げ直す往復
- ヘッダー整合・サンプル列数・BOM の読み戻し
- コントローラが出した行エラーが画面に出ていること

よってこの計画に残るのは、設計書 §4.4 の**残り 3 本**（`CsvImportReaderTest` /
`CsvDateTest` / `MansionImportTest`）と、共用部品の切り出し・`CsvDate` の修正・UNIQUE の追加。

---

## File Structure

| ファイル | 責務 |
|---|---|
| `app/Support/CsvDate.php`（新規） | `YYYY-MM-DD` 正規化のみ。DB も HTTP も知らない |
| `app/Support/CsvImportReader.php`（新規） | 生バイト列 → 行配列。文字コード判定・BOM 除去・ヘッダー写像・必須ヘッダー検査 |
| `app/Support/CsvImportException.php`（新規） | 書式異常。`getMessage()` が画面に出る日本語をそのまま返す |
| `app/Support/CsvImportTemplate.php`（新規） | CSV 行のエスケープと BOM 付きダウンロード応答 |
| `app/Http/Controllers/Admin/MansionImportController.php`（修正） | ヘルパー 4 本を削り Support を呼ぶ |
| `app/Http/Controllers/Admin/TenantImportController.php`（修正） | 同上 |
| `tests/Concerns/CreatesMansionSchema.php`（修正） | 本番 DDL の UNIQUE 3 本を追加 |
| `tests/Unit/Support/CsvDateTest.php`（新規） | 日付の境界値 |
| `tests/Unit/Support/CsvImportReaderTest.php`（新規） | パースの境界値 |
| `tests/Feature/Admin/MansionImportTest.php`（新規） | 6 経路の往復＋固有リスク |
| `docs/RULES.md`（追記） | 判明した罠を Bug #54 として |

---

## Task 1: `CsvDate` を作る（振る舞いは現状のまま）

**なぜ現状のままから始めるか:** 移動と修正を混ぜると「移動で壊れた」と「修正で変わった」を切り分けられない。まず現状の振る舞いを固定してから Task 6 で直す。

**Files:**
- Create: `app/Support/CsvDate.php`
- Test: `tests/Unit/Support/CsvDateTest.php`

- [ ] **Step 1: 現状の振る舞いを固定する失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\CsvDate;
use PHPUnit\Framework\TestCase;

/**
 * CSV 取込の日付正規化。
 *
 * ⚠ このテストは **Task 1 時点では「現状の（誤った）振る舞い」を固定している**。
 *   Task 6 で `checkdate()` に直すとき、期待値を正しい側へ書き換える。
 */
class CsvDateTest extends TestCase
{
    public function test_it_pads_and_accepts_slashes(): void
    {
        $this->assertSame('2026-04-01', CsvDate::normalize('2026-04-01'));
        $this->assertSame('2026-04-01', CsvDate::normalize('2026/04/01'));
        $this->assertSame('2026-02-03', CsvDate::normalize('2026-2-3'));
    }

    public function test_it_rejects_garbage(): void
    {
        $this->assertNull(CsvDate::normalize(''));
        $this->assertNull(CsvDate::normalize('2026-13-01'));
        $this->assertNull(CsvDate::normalize('令和8年4月1日'));
    }
}
```

- [ ] **Step 2: 落ちることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvDateTest.php`
Expected: FAIL — `Class "App\Support\CsvDate" not found`

- [ ] **Step 3: `MansionImportController::normalizeDate()` の中身をそのまま移す**

```php
<?php

namespace App\Support;

/**
 * CSV 取込の日付正規化。
 *
 * 両取込コントローラ（賃貸マンション / テナント）が `normalizeDate()` として
 * 逐語コピーで持っていたものを 1 本化した（実測でコメント除去後まで完全一致）。
 */
final class CsvDate
{
    /** `YYYY-MM-DD` へ正規化する。解釈できなければ null。 */
    public static function normalize(string $value): ?string
    {
        $value = str_replace('/', '-', $value);

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) {
            $parts = explode('-', $value);

            return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
        }

        return null;
    }
}
```

- [ ] **Step 4: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvDateTest.php`
Expected: OK (2 tests)

- [ ] **Step 5: コミット**

```bash
git add app/Support/CsvDate.php tests/Unit/Support/CsvDateTest.php
git commit -m "refactor(support): CSV 取込の日付正規化を CsvDate へ切り出す（振る舞い不変）"
```

---

## Task 2: `CsvImportReader` を作る（振る舞いは現状のまま）

**Files:**
- Create: `app/Support/CsvImportReader.php`, `app/Support/CsvImportException.php`
- Test: `tests/Unit/Support/CsvImportReaderTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use PHPUnit\Framework\TestCase;

class CsvImportReaderTest extends TestCase
{
    /** @var array<string, string> */
    private const MAP = ['物件名' => 'name', '住所' => 'address', '備考' => 'notes'];

    private const REQUIRED = ['name', 'address'];

    public function test_it_strips_the_bom_and_maps_headers(): void
    {
        $csv = "\xEF\xBB\xBF物件名,住所,備考\nミツワビル,松山市一番町,メモ\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(
            [['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => 'メモ']],
            $rows
        );
    }

    public function test_it_converts_shift_jis(): void
    {
        $utf8 = "物件名,住所\nミツワビル,松山市一番町\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

        $rows = CsvImportReader::parse(CsvImportReader::decode($sjis), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル', $rows[0]['name']);
    }

    public function test_it_tolerates_crlf(): void
    {
        $csv = "物件名,住所\r\nミツワビル,松山市一番町\r\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('松山市一番町', $rows[0]['address']);
    }

    public function test_it_keeps_commas_inside_quotes(): void
    {
        $csv = "物件名,住所\n\"ミツワビル,別館\",松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル,別館', $rows[0]['name']);
    }

    public function test_a_short_row_yields_empty_strings_not_missing_keys(): void
    {
        $csv = "物件名,住所,備考\nミツワビル,松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('', $rows[0]['notes']);
        $this->assertArrayHasKey('notes', $rows[0]);
    }

    public function test_it_ignores_columns_it_does_not_know(): void
    {
        $csv = "物件名,知らない列,住所\nミツワビル,X,松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => ''], $rows[0]);
    }

    public function test_it_rejects_a_file_with_no_data_rows(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('CSVファイルにデータがありません。');

        CsvImportReader::parse("物件名,住所\n", self::MAP, self::REQUIRED);
    }

    public function test_it_names_the_missing_required_header_in_japanese(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('必須ヘッダー「住所」がCSVに見つかりません。');

        CsvImportReader::parse("物件名,備考\nミツワビル,メモ\n", self::MAP, self::REQUIRED);
    }

    public function test_it_skips_blank_lines(): void
    {
        $csv = "物件名,住所\n\nミツワビル,松山市一番町\n\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertCount(1, $rows);
    }
}
```

- [ ] **Step 2: 落ちることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvImportReaderTest.php`
Expected: FAIL — `Class "App\Support\CsvImportReader" not found`

- [ ] **Step 3: 例外クラスを作る**

```php
<?php

namespace App\Support;

/**
 * CSV の書式が受け付けられないときに投げる。
 *
 * `getMessage()` はそのまま画面に出る日本語なので、英語のメッセージを入れないこと
 * （コントローラが `back()->with('error', $e->getMessage())` で表示する）。
 */
final class CsvImportException extends \RuntimeException
{
}
```

- [ ] **Step 4: `loadCsv()` の純粋部分をそのまま移す**

```php
<?php

namespace App\Support;

/**
 * CSV 取込の読み取り。
 *
 * 両取込コントローラ（賃貸マンション / テナント）が `loadCsv()` として逐語コピーで
 * 持っていた処理のうち、**HTTP を知らない部分だけ**を 1 本化した
 * （実測でコメント除去後まで完全一致）。
 *
 * ファイル取得・base64 復元・`back()` での差し戻しはコントローラに残す。
 */
final class CsvImportReader
{
    /** 文字コードを UTF-8 に揃え、BOM を落とす。 */
    public static function decode(string $raw): string
    {
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }

        return preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    }

    /**
     * ヘッダー行を内部キーへ写像して行配列にする。
     *
     * @param  array<string, string>  $columnMap  日本語ヘッダー => 内部キー
     * @param  list<string>  $requiredKeys  無ければ弾く内部キー
     * @return list<array<string, string>>
     *
     * @throws CsvImportException データ行が無い / 必須ヘッダーが無い
     */
    public static function parse(string $content, array $columnMap, array $requiredKeys): array
    {
        $lines = array_values(array_filter(
            explode("\n", $content),
            fn (string $line): bool => trim($line) !== ''
        ));

        if (count($lines) < 2) {
            throw new CsvImportException('CSVファイルにデータがありません。');
        }

        $header = array_map('trim', str_getcsv(array_shift($lines)));

        $colIndex = [];
        foreach ($header as $idx => $headerName) {
            if (isset($columnMap[$headerName])) {
                $colIndex[$columnMap[$headerName]] = $idx;
            }
        }

        foreach ($requiredKeys as $key) {
            if (! isset($colIndex[$key])) {
                $jpName = array_search($key, $columnMap, true);

                throw new CsvImportException("必須ヘッダー「{$jpName}」がCSVに見つかりません。");
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row  = [];

            foreach ($columnMap as $key) {
                $idx      = $colIndex[$key] ?? -1;
                $row[$key] = ($idx >= 0 && isset($cols[$idx])) ? trim($cols[$idx]) : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
```

- [ ] **Step 5: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvImportReaderTest.php`
Expected: OK (9 tests)

- [ ] **Step 6: コミット**

```bash
git add app/Support/CsvImportReader.php app/Support/CsvImportException.php tests/Unit/Support/CsvImportReaderTest.php
git commit -m "refactor(support): CSV 取込の読み取りを CsvImportReader へ切り出す（振る舞い不変）"
```

---

## Task 3: `CsvImportTemplate` を作る（振る舞いは現状のまま）

**Files:**
- Create: `app/Support/CsvImportTemplate.php`
- Test: `tests/Unit/Support/CsvImportTemplateTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\CsvImportTemplate;
use PHPUnit\Framework\TestCase;

class CsvImportTemplateTest extends TestCase
{
    public function test_it_quotes_every_field(): void
    {
        $this->assertSame("\"a\",\"b\"\n", CsvImportTemplate::line(['a', 'b']));
    }

    public function test_it_doubles_embedded_quotes(): void
    {
        $this->assertSame("\"say \"\"hi\"\"\"\n", CsvImportTemplate::line(['say "hi"']));
    }

    public function test_the_response_starts_with_a_bom_so_excel_reads_utf8(): void
    {
        $response = CsvImportTemplate::response(['物件名'], [['ミツワビル']], 'x.csv');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertSame("\xEF\xBB\xBF\"物件名\"\n\"ミツワビル\"\n", $response->getContent());
    }

    public function test_the_response_is_an_attachment(): void
    {
        $response = CsvImportTemplate::response(['A'], [], 'テンプレート.csv');

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename="テンプレート.csv"',
            $response->headers->get('Content-Disposition')
        );
    }
}
```

- [ ] **Step 2: 落ちることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvImportTemplateTest.php`
Expected: FAIL — `Class "App\Support\CsvImportTemplate" not found`

- [ ] **Step 3: `toCsvLine()` / `buildCsvResponse()` をそのまま移す**

```php
<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * CSV 取込テンプレートの配信。
 *
 * 両取込コントローラが `toCsvLine()` / `buildCsvResponse()` として逐語コピーで
 * 持っていたものを 1 本化した（実測でコメント除去後まで完全一致）。
 */
final class CsvImportTemplate
{
    /** 配列を CSV の 1 行にする（全項目を引用符で囲む）。 */
    public static function line(array $fields): string
    {
        $escaped = [];

        foreach ($fields as $field) {
            $escaped[] = '"' . str_replace('"', '""', $field) . '"';
        }

        return implode(',', $escaped) . "\n";
    }

    /**
     * BOM 付き UTF-8 のダウンロード応答を作る。
     *
     * BOM が無いと Excel が Shift_JIS として開いて日本語が化ける。
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $sampleRows
     */
    public static function response(array $headers, array $sampleRows, string $filename): Response
    {
        $csv = "\xEF\xBB\xBF" . self::line($headers);

        foreach ($sampleRows as $sample) {
            $csv .= self::line($sample);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
```

- [ ] **Step 4: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvImportTemplateTest.php`
Expected: OK (4 tests)

- [ ] **Step 5: コミット**

```bash
git add app/Support/CsvImportTemplate.php tests/Unit/Support/CsvImportTemplateTest.php
git commit -m "refactor(support): CSV テンプレート配信を CsvImportTemplate へ切り出す（振る舞い不変）"
```

---

## Task 4: 両コントローラを Support へ載せ替える（振る舞い不変）

**安全網:** `ImportPreviewRenderTest` が 12 経路のプレビュー描画とテンプレート往復を全件で見ているので、この載せ替えを壊すと即座に赤くなる。

**Files:**
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`

- [ ] **Step 1: 載せ替え前のテストが緑であることを確認する**

Run: `./vendor/bin/phpunit`
Expected: OK (846 tests 前後) — この数を控えておく

- [ ] **Step 2: `MansionImportController` の `loadCsv()` を書き換える**

`private function loadCsv(...)` の本体を次で置き換える（メソッド名とシグネチャは変えない。呼び出し側 6 箇所を触らずに済む）:

```php
    /**
     * CSV を読み込んで行配列にする。
     *
     * 純粋な読み取りは [[\App\Support\CsvImportReader]] にある。ここに残るのは
     * HTTP 依存の 3 つだけ: ファイル取得 / 確定時の base64 復元 / 差し戻し。
     *
     * @return array{0: list<array<string, string>>, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function loadCsv(Request $request, array $columnMap, array $requiredKeys)
    {
        if ($request->boolean('confirmed')) {
            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
            $content = base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $content = CsvImportReader::decode(
                file_get_contents($request->file('csv_file')->getRealPath())
            );
        }

        try {
            $rows = CsvImportReader::parse($content, $columnMap, $requiredKeys);
        } catch (CsvImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return [$rows, $content];
    }
```

- [ ] **Step 3: `MansionImportController` の残り 3 ヘルパーを削って委譲する**

`normalizeDate()` / `toCsvLine()` / `buildCsvResponse()` の 3 メソッドを**削除**し、呼び出しを置き換える:

- `$this->normalizeDate($x)` → `CsvDate::normalize($x)`（6 箇所）
- `$this->buildCsvResponse($h, $s, $f)` → `CsvImportTemplate::response($h, $s, $f)`（6 箇所）
- `$this->toCsvLine(...)` の直接呼び出しは無い（`buildCsvResponse` 経由のみ）

ファイル先頭の `use` に次を足す:

```php
use App\Support\CsvDate;
use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use App\Support\CsvImportTemplate;
```

- [ ] **Step 4: 賃貸マンション側だけで一度測る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/ImportPreviewRenderTest.php`
Expected: OK (2 tests) — ここが赤ければ載せ替えを壊している

- [ ] **Step 5: `TenantImportController` に同じ 3 手順を当てる**

`loadCsv()` は Step 2 と**同一の本体**（逐語コピーだったので差が無い）。
`normalizeDate()` は 5 箇所、`buildCsvResponse()` は 5 箇所。
⚠ `getNextPropertyCodeNum()` は**触らない**（賃貸マンションと実装が違う。設計書 §3）。

- [ ] **Step 6: 全テストで振る舞いが変わっていないことを見る**

Run: `./vendor/bin/phpunit`
Expected: OK — Step 1 と**同じ本数**が緑

- [ ] **Step 7: 残骸が無いことを確認する**

```bash
grep -n "normalizeDate\|toCsvLine\|buildCsvResponse" app/Http/Controllers/Admin/*ImportController.php
```
Expected: 出力なし

- [ ] **Step 8: コミット**

```bash
git add app/Http/Controllers/Admin/MansionImportController.php app/Http/Controllers/Admin/TenantImportController.php
git commit -m "refactor(admin): 両取込コントローラの逐語コピー 4 本を Support へ寄せる（振る舞い不変）"
```

---

## Task 5: テスト用スキーマに本番の UNIQUE 制約を足す

**なぜ先にやるか:** Task 7 以降の重複判定テストは、UNIQUE が無いと「重複を検出できていなくても緑」になる（Bug #40 と同型）。測定器を先に直す。

**Files:**
- Modify: `tests/Concerns/CreatesMansionSchema.php`

- [ ] **Step 1: 本番 DDL と突き合わせる**

```bash
grep -nE "UNIQUE KEY" database/sql/create_mansion_tables.sql
```
Expected:
```
21:  UNIQUE KEY `uk_ms_properties_code` (`property_code`)
39:  UNIQUE KEY `uk_ms_rooms_property_room` (`property_id`, `room_number`),
53:  UNIQUE KEY `uk_ms_parkings_property_number` (`property_id`, `parking_number`),
```

- [ ] **Step 2: 3 本を trait に足す**

`ms_properties` の `$t->timestamps();` の直前:
```php
            $t->unique('property_code', 'uk_ms_properties_code');
```

`ms_rooms` の `$t->timestamps();` の直前:
```php
            $t->unique(['property_id', 'room_number'], 'uk_ms_rooms_property_room');
```

`ms_parkings` の `$t->timestamps();` の直前:
```php
            $t->unique(['property_id', 'parking_number'], 'uk_ms_parkings_property_number');
```

- [ ] **Step 3: 既存テストが落ちないか測る**

Run: `./vendor/bin/phpunit`
Expected: OK

⚠ **ここで既存の賃貸マンションテストが落ちたら、緑に戻す前に原因を追うこと。**
本番の制約下で成立していない振る舞いを見つけたということで、それ自体が成果。
落ちたら Task を止めてユーザーに報告する。

- [ ] **Step 4: コミット**

```bash
git add tests/Concerns/CreatesMansionSchema.php
git commit -m "test(mansion): テスト用スキーマに本番 DDL の UNIQUE 制約 3 本を足す"
```

---

## Task 6: `CsvDate::normalize()` を `checkdate()` で直す

**Files:**
- Modify: `app/Support/CsvDate.php`
- Modify: `tests/Unit/Support/CsvDateTest.php`

- [ ] **Step 1: 正しい振る舞いを求める失敗するテストに書き換える**

`CsvDateTest` に次の 2 本を**追加**する（Task 1 の 2 本はそのまま残す）:

```php
    /**
     * 存在しない日付を弾くこと。
     *
     * ⚠ **この 4 つの値を消さないこと。** `strtotime()` は存在しない日付を
     *   繰り上げて成功を返すので（`2026-02-30` → 3/2 として解釈）、
     *   誤実装に戻したときに落ちるのはこれらの値だけ。
     *
     * ⚠ 本番 MySQL は strict mode なので `'2026-02-30'` の INSERT は
     *   `Incorrect date value` で例外になり `rollBack()` する。つまり
     *   **打鍵ミス 1 行で数百行の取込が丸ごと失敗**していた。
     */
    public function test_it_rejects_dates_that_do_not_exist(): void
    {
        $this->assertNull(CsvDate::normalize('2026-02-30'));
        $this->assertNull(CsvDate::normalize('2026-04-31'));
        $this->assertNull(CsvDate::normalize('2026-02-29')); // 2026 は閏年でない
        $this->assertNull(CsvDate::normalize('0000-01-01'));
    }

    /**
     * 1970-01-01 を受け付けること。
     *
     * ⚠ **この値を消さないこと。** `config/app.php` の timezone は `'UTC'` なので
     *   `strtotime('1970-01-01')` は **0**（falsy）を返す。`&& strtotime($value)` で
     *   真偽判定していた旧実装は、この 1 日だけを理由なく拒否していた。
     */
    public function test_it_accepts_the_unix_epoch(): void
    {
        $this->assertSame('1970-01-01', CsvDate::normalize('1970-01-01'));
    }
```

- [ ] **Step 2: 落ちることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvDateTest.php`
Expected: FAIL 2 本
- `Failed asserting that '2026-02-30' is null.`
- `Failed asserting that null is identical to '1970-01-01'.`

- [ ] **Step 3: `checkdate()` に置き換える**

```php
    /** `YYYY-MM-DD` へ正規化する。解釈できなければ null。 */
    public static function normalize(string $value): ?string
    {
        $value = str_replace('/', '-', $value);

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        // strtotime() は存在しない日付を繰り上げて成功を返すので使わない
        // （2026-02-30 → 3/2 と解釈される）。さらに timezone が UTC のとき
        // strtotime('1970-01-01') === 0 が falsy になり、その 1 日だけ拒否される。
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
```

- [ ] **Step 4: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Unit/Support/CsvDateTest.php`
Expected: OK (4 tests)

- [ ] **Step 5: 全テストで副作用が無いことを見る**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 6: コミット**

```bash
git add app/Support/CsvDate.php tests/Unit/Support/CsvDateTest.php
git commit -m "fix(support): 存在しない日付を通し 1970-01-01 を拒否していた CSV 日付正規化を直す"
```

---

## Task 7: 賃貸マンション取込の Feature テスト土台

**Files:**
- Create: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 土台と最初の往復テスト（物件）を書く**

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\MsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesMansionSchema;
use Tests\TestCase;

/**
 * 賃貸マンション CSV 取込の振る舞い。
 *
 * ⚠ **`MansionImportController` は追加以来ノータッチ（1 コミット）で、
 *   振る舞いのテストが 1 本も無かった**（2026-08-18 時点で 1,489 行）。
 *   本番の `ms_*` が全テーブル 0 件なので誰も踏んでいないが、
 *   これから一括投入するときに初めて踏む。
 *
 * ⚠ **プレビューと確定は別の HTTP リクエスト。** 確定側は画面が持ち回った base64 から
 *   CSV を復元し、行バリデーションを最初からやり直す。よってテストも
 *   **画面が描画した hidden をそのまま送り返す**（Bug #47 の往復テスト）。
 *   URL を `assertSee` で見るだけでは配線の半分も押さえられない。
 */
class MansionImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プレビューを描画させる（確定はしない）。 */
    private function preview(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->post("/admin/mansion-import/{$tab}", [
            'csv_file' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF" . $csv),
        ]);
    }

    /**
     * プレビュー → 確定の往復。
     *
     * **画面が描画した `csv_data` hidden を抜き出して送り返す**ので、
     * その hidden が消えたり名前が変わったりすれば赤くなる。
     * 自前で base64 を組み立ててはいけない（画面が壊れていても緑になる）。
     */
    private function confirm(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $matched = preg_match(
            '/<input type="hidden" name="csv_data" value="([^"]*)">/',
            $preview->getContent(),
            $m
        );

        $this->assertSame(1, $matched, "プレビュー画面に csv_data hidden が無い（tab={$tab}）");

        return $this->actingAs($this->executive())->post("/admin/mansion-import/{$tab}", [
            'confirmed' => '1',
            'csv_data'  => $m[1],
        ]);
    }

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    public function test_property_round_trip_creates_the_row(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "ミツワレジデンス,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,メモ\n";

        $response = $this->confirm('property', $csv);

        $response->assertRedirect('/admin/mansion-import?selected_tab=property');
        $response->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $property = MsProperty::sole();
        $this->assertSame('ミツワレジデンス', $property->property_name);
        $this->assertSame('MS-001', $property->property_code);
        $this->assertSame('self_owned', $property->ownership_type->value);
        $this->assertSame('愛媛県松山市一番町1-1', $property->address);
        $this->assertSame(20, $property->total_units);
        $this->assertSame(5, $property->total_floors);
        $this->assertSame('2010-04', $property->built_year_month);
    }
}
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (1 test)

⚠ 落ちたら**期待値ではなく実装を疑う前に**、まず実際の値を出して確かめる
（このテストは既存実装の振る舞いを固定するのが目的で、仕様を変えるものではない）。

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 物件 CSV 取込のプレビュー→確定の往復を固定する"
```

---

## Task 8: 物件取込の固有リスク（採番・重複・既存スキップ）

**Files:**
- Modify: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 3 本を追加する**

```php
    /**
     * 物件コードは連番で採番される。
     *
     * ⚠ 本番の `ms_properties.property_code` は UNIQUE。採番が重複すると
     *   例外 → `rollBack()` で**取込全体が失敗**する（1 行も入らない）。
     */
    public function test_property_codes_are_numbered_sequentially(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "A棟,自社所有,,,松山市1,,,,,\n"
             . "B棟,自社所有,,,松山市2,,,,,\n"
             . "C棟,自社所有,,,松山市3,,,,,\n";

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 3件を登録しました');

        $this->assertSame(
            ['MS-001', 'MS-002', 'MS-003'],
            MsProperty::orderBy('id')->pluck('property_code')->all()
        );
    }

    /** 採番は既存の続きから始まる（既存 MS-001 があれば次は MS-002）。 */
    public function test_property_codes_continue_from_the_existing_maximum(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n先客,自社所有,,,松山市0,,,,,\n");

        $this->confirm('property', self::PROPERTY_HEADER . "\n後客,自社所有,,,松山市9,,,,,\n");

        $this->assertSame(
            ['MS-001', 'MS-002'],
            MsProperty::orderBy('id')->pluck('property_code')->all()
        );
    }

    /**
     * CSV 内で物件名が重複したらエラー行になり、**その行だけ**落ちる。
     *
     * ⚠ 「2 行目が落ちる」ではなく「1 行目は入り 2 行目だけ落ちる」ことを見る。
     *   全体が落ちる実装に変異したときに赤くなる。
     */
    public function test_a_duplicate_name_inside_the_csv_drops_only_that_row(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "同名,自社所有,,,松山市1,,,,,\n"
             . "同名,自社所有,,,松山市2,,,,,\n"
             . "別名,自社所有,,,松山市3,,,,,\n";

        $preview = $this->preview('property', $csv);
        $preview->assertStatus(200);
        $preview->assertSee('物件名「同名」がCSV内で重複しています', false);

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 2件を登録しました');

        $this->assertSame(['同名', '別名'], MsProperty::orderBy('id')->pluck('property_name')->all());
    }

    /** DB に同名が既にあれば「スキップ」（エラーではない）。 */
    public function test_an_existing_name_is_skipped_not_errored(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n先客,自社所有,,,松山市0,,,,,\n");

        $csv = self::PROPERTY_HEADER . "\n"
             . "先客,自社所有,,,松山市1,,,,,\n"
             . "新顔,自社所有,,,松山市2,,,,,\n";

        $preview = $this->preview('property', $csv);
        $preview->assertSee('物件「先客」は既に登録済みのためスキップ', false);

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $this->assertSame(2, MsProperty::count());
    }
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (5 tests)

⚠ `test_property_codes_continue_from_the_existing_maximum` が落ちたら**実装の欠陥**の可能性がある
（`getNextPropertyCodeNum()` は `orderByDesc('id')` で最後に作った行のコード + 1 を返す）。
落ちた場合は Task を止めて実測結果をユーザーに報告する。

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 物件取込の採番・CSV内重複・既存スキップを固定する"
```

---

## Task 9: 部屋取込（親の存在・UNIQUE・total_units 再集計）

**Files:**
- Modify: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 4 本を追加する**

```php
    private const ROOM_HEADER = '物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考';

    private function seedProperty(string $name = 'ミツワレジデンス'): MsProperty
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n{$name},自社所有,,,松山市1,,,,,\n");

        return MsProperty::where('property_name', $name)->sole();
    }

    public function test_room_round_trip_creates_the_row(): void
    {
        $property = $this->seedProperty();

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,1,1K,25.50,空室,55000,3000,55000,55000,メモ\n";

        $this->confirm('room', $csv)->assertSessionHas('success', '部屋インポート完了: 1件を登録しました');

        $room = \App\Models\MsRoom::sole();
        $this->assertSame($property->id, $room->property_id);
        $this->assertSame('101', $room->room_number);
        $this->assertSame(1, $room->floor);
        $this->assertSame('vacant', $room->status->value);
        $this->assertSame(55000, $room->rent);
        $this->assertSame('25.50', $room->area_sqm);
    }

    /** 物件が未登録ならエラー行になり、部屋は作られない。 */
    public function test_a_room_whose_property_is_missing_is_an_error_row(): void
    {
        $preview = $this->preview('room', self::ROOM_HEADER . "\n知らない物件,101,,,,,,,,,\n");

        $preview->assertSee('物件「知らない物件」がシステムに登録されていません', false);

        $this->confirm('room', self::ROOM_HEADER . "\n知らない物件,101,,,,,,,,,\n")
            ->assertSessionHas('success', '部屋インポート完了: 0件を登録しました');

        $this->assertSame(0, \App\Models\MsRoom::count());
    }

    /**
     * 同じ物件の同じ部屋番号は 2 度入らない。
     *
     * ⚠ **本番は `UNIQUE (property_id, room_number)`。** アプリ側の重複判定が
     *   死ぬと例外 → `rollBack()` で取込全体が落ちる。
     *   `CreatesMansionSchema` に UNIQUE を足してあるので、この形が本番同等で測れる。
     */
    public function test_a_room_number_already_in_the_database_is_an_error_row(): void
    {
        $this->seedProperty();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,101,,,,,,,,,\n");

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,,,,,,,,,\n"
             . "ミツワレジデンス,102,,,,,,,,,\n";

        $preview = $this->preview('room', $csv);
        $preview->assertSee('の部屋「101」は既に登録されています', false);

        $this->confirm('room', $csv)->assertSessionHas('success', '部屋インポート完了: 1件を登録しました');

        $this->assertSame(['101', '102'], \App\Models\MsRoom::orderBy('id')->pluck('room_number')->all());
    }

    /**
     * 取込後に `total_units` が実際の部屋数で上書きされる。
     *
     * ⚠ **物件取込で入れた値は捨てられる**（画面にも「※ 総戸数は部屋インポート後に
     *   自動再集計で上書きされます」と出ている）。ここを固定しておかないと、
     *   再集計を消す変異が素通りする。
     */
    public function test_total_units_is_recalculated_after_importing_rooms(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\nミツワレジデンス,自社所有,,,松山市1,99,,,,\n");
        $this->assertSame(99, MsProperty::sole()->total_units);

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,,,,,,,,,\n"
             . "ミツワレジデンス,102,,,,,,,,,\n";

        $this->confirm('room', $csv);

        $this->assertSame(2, MsProperty::sole()->total_units);
    }
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (9 tests)

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 部屋取込の親存在チェック・UNIQUE・total_units 再集計を固定する"
```

---

## Task 10: 駐車場・入居者取込

**Files:**
- Modify: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 4 本を追加する**

```php
    private const PARKING_HEADER = '物件名,駐車場番号,月額料金,状態,屋根あり,備考';

    public function test_parking_round_trip_creates_the_row(): void
    {
        $property = $this->seedProperty();

        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,有,メモ\n")
            ->assertSessionHas('success', '駐車場インポート完了: 1件を登録しました');

        $parking = \App\Models\MsParking::sole();
        $this->assertSame($property->id, $parking->property_id);
        $this->assertSame('P-1', $parking->parking_number);
        $this->assertSame(8000, $parking->monthly_fee);
        $this->assertSame('vacant', $parking->status->value);
        $this->assertTrue($parking->has_roof);
    }

    /**
     * 「屋根あり」は表記ゆれを受ける。
     *
     * ⚠ **未入力は false 扱い**（`hasRoofMap` に無い値も同じ）。
     *   null にする変異を入れたら赤くなるよう、明示的に false を見る。
     */
    public function test_the_roof_flag_accepts_common_spellings(): void
    {
        $this->seedProperty();

        $csv = self::PARKING_HEADER . "\n"
             . "ミツワレジデンス,P-1,8000,空き,有,\n"
             . "ミツワレジデンス,P-2,8000,空き,あり,\n"
             . "ミツワレジデンス,P-3,8000,空き,無,\n"
             . "ミツワレジデンス,P-4,8000,空き,,\n";

        $this->confirm('parking', $csv);

        $this->assertSame(
            [true, true, false, false],
            \App\Models\MsParking::orderBy('id')->pluck('has_roof')->all()
        );
    }

    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    public function test_tenant_round_trip_creates_the_row(): void
    {
        $csv = self::TENANT_HEADER . "\n"
             . "入居者,山田太郎,090-1234-5678,taro@example.com,株式会社サンプル,山田花子,090-9876-5432,配偶者,メモ\n";

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $tenant = \App\Models\MsTenant::sole();
        $this->assertSame('resident', $tenant->tenant_type->value);
        $this->assertSame('山田太郎', $tenant->name);
        $this->assertSame('taro@example.com', $tenant->email);
        $this->assertSame('配偶者', $tenant->emergency_contact_relation);
    }

    /** 不正なメールアドレスはエラー行になる（その行だけ落ちる）。 */
    public function test_an_invalid_email_drops_only_that_row(): void
    {
        $csv = self::TENANT_HEADER . "\n"
             . "入居者,壊れた人,,not-an-email,,,,,\n"
             . "入居者,まともな人,,ok@example.com,,,,,\n";

        $preview = $this->preview('tenant', $csv);
        $preview->assertSee('メールアドレス「not-an-email」の形式が不正です', false);

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $this->assertSame(['まともな人'], \App\Models\MsTenant::pluck('name')->all());
    }
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (13 tests)

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 駐車場・入居者取込の往復と表記ゆれ・検証を固定する"
```

---

## Task 11: 部屋契約取込（日付・ステータス連動・二重契約警告）

**Files:**
- Modify: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 4 本を追加する**

```php
    private const ROOM_CONTRACT_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';

    private function seedRoomAndTenant(): void
    {
        $this->seedProperty();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,101,,,,,,,,,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n");
    }

    /**
     * 退去日が無ければ active になり、**部屋のステータスが occupied に変わる**。
     *
     * ⚠ 契約を作るだけでなく親の部屋を書き換える副作用があるので、両方見る。
     */
    public function test_an_active_room_contract_marks_the_room_occupied(): void
    {
        $this->seedRoomAndTenant();

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,メモ\n";

        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsContract::sole();
        $this->assertSame('active', $contract->status->value);
        $this->assertSame('2024-04-01', $contract->contract_date->toDateString());
        $this->assertSame('2024-04-15', $contract->move_in_date->toDateString());
        $this->assertNull($contract->move_out_date);

        $this->assertSame('occupied', \App\Models\MsRoom::sole()->status->value);
    }

    /** 退去日があれば terminated になり、部屋は occupied にしない。 */
    public function test_a_terminated_room_contract_leaves_the_room_alone(): void
    {
        $this->seedRoomAndTenant();

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2024-04-01,2024-04-15,2025-03-31,,,,,,\n";

        $this->confirm('room-contract', $csv);

        $this->assertSame('terminated', \App\Models\MsContract::sole()->status->value);
        $this->assertSame('vacant', \App\Models\MsRoom::sole()->status->value);
    }

    /**
     * 存在しない日付はエラー行になり、**その行だけ**落ちる。
     *
     * ⚠ **これが Task 6 の修正の効き目を実データ経路で見るテスト。**
     *   旧実装（`strtotime` の真偽判定）は `2026-02-30` を素通りさせ、
     *   本番 MySQL で `Incorrect date value` → `rollBack()` ＝
     *   **正しい行まで含めて 1 件も入らない**状態だった。
     */
    public function test_an_impossible_contract_date_drops_only_that_row(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,102,,,,,,,,,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,鈴木次郎,,,,,,,\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2026-02-30,,,,,,,,\n"
             . "ミツワレジデンス,102,鈴木次郎,2026-04-01,,,,,,,,\n";

        $preview = $this->preview('room-contract', $csv);
        $preview->assertSee('契約日「2026-02-30」の形式が不正です', false);

        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsContract::sole();
        $this->assertSame('2026-04-01', $contract->contract_date->toDateString());
    }

    /**
     * 既に契約中の部屋には**警告**が出るが、取り込みは止まらない。
     *
     * ⚠ 警告（`warnings`）とエラー（`rowErrors`）は別物。警告をエラーに変える変異で
     *   赤くなるよう、「警告が出ること」と「それでも入ること」を対で見る。
     */
    public function test_a_second_active_contract_warns_but_still_imports(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,鈴木次郎,,,,,,,\n");

        $this->confirm('room-contract', self::ROOM_CONTRACT_HEADER
            . "\nミツワレジデンス,101,山田太郎,2024-04-01,,,,,,,,\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\nミツワレジデンス,101,鈴木次郎,2025-04-01,,,,,,,,\n";

        $preview = $this->preview('room-contract', $csv);
        $preview->assertSee('には既に契約中の入居者がいます', false);

        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $this->assertSame(2, \App\Models\MsContract::count());
    }
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (17 tests)

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 部屋契約取込の日付・ステータス連動・二重契約警告を固定する"
```

---

## Task 12: 駐車場契約取込（部屋契約への紐付け・ステータス連動）

**Files:**
- Modify: `tests/Feature/Admin/MansionImportTest.php`

- [ ] **Step 1: 3 本を追加する**

```php
    private const PARKING_CONTRACT_HEADER = '物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ';

    public function test_an_active_parking_contract_marks_the_parking_occupied(): void
    {
        $this->seedProperty();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n駐車場利用のみ,佐藤三郎,,,,,,,\n");

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,佐藤三郎,,2024-04-01,2024-04-15,,8000,8000,,メモ\n";

        $this->confirm('parking-contract', $csv)
            ->assertSessionHas('success', '駐車場契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsParkingContract::sole();
        $this->assertSame('active', $contract->status->value);
        $this->assertSame(8000, $contract->monthly_fee);
        $this->assertNull($contract->contract_id);

        $this->assertSame('occupied', \App\Models\MsParking::sole()->status->value);
    }

    /**
     * 紐付部屋番号を指定すると、その部屋の **active な部屋契約 ID** が入る。
     *
     * ⚠ 紐付けを消す変異（常に null にする）で赤くなるよう、ID の一致まで見る。
     */
    public function test_a_linked_room_number_attaches_the_active_room_contract(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('room-contract', self::ROOM_CONTRACT_HEADER
            . "\nミツワレジデンス,101,山田太郎,2024-04-01,,,,,,,,\n");

        $roomContractId = \App\Models\MsContract::sole()->id;

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,山田太郎,101,2024-04-01,2024-04-15,,8000,,,\n";

        $this->confirm('parking-contract', $csv);

        $this->assertSame($roomContractId, \App\Models\MsParkingContract::sole()->contract_id);
    }

    /** 終了日があれば terminated になり、駐車場は空きのまま。 */
    public function test_a_terminated_parking_contract_leaves_the_parking_vacant(): void
    {
        $this->seedProperty();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n駐車場利用のみ,佐藤三郎,,,,,,,\n");

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,佐藤三郎,,2024-04-01,2024-04-15,2025-03-31,8000,,,\n";

        $this->confirm('parking-contract', $csv);

        $this->assertSame('terminated', \App\Models\MsParkingContract::sole()->status->value);
        $this->assertSame('vacant', \App\Models\MsParking::sole()->status->value);
    }
```

- [ ] **Step 2: 通ることを見る**

Run: `./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php`
Expected: OK (20 tests)

- [ ] **Step 3: 全テストを回す**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 4: コミット**

```bash
git add tests/Feature/Admin/MansionImportTest.php
git commit -m "test(mansion): 駐車場契約取込の部屋契約紐付けとステータス連動を固定する"
```

---

## Task 13: 変異テストで検出力を実測する

**「テストが緑」は検証にならない。** 変異を入れて赤になることと、**落ちた理由の文言**まで確かめる。

- [ ] **Step 1: 前提を整える**

```bash
git status --porcelain
```
Expected: 出力なし（Bug #44 — 未コミットのまま変異を当てると `git checkout --` で自分の編集ごと巻き戻る）

- [ ] **Step 2: 変異を 1 つずつ当てて、赤になる理由まで記録する**

各変異について ①`git status --porcelain` が空であることを確認 ②変異を当てる
③`git diff --stat` が非空であることを確認 ④テストを回す ⑤`git checkout -- .` で戻す。

| # | 変異 | 当てる場所 | 期待する赤とその理由 |
|---|---|---|---|
| 1 | `CsvDate::normalize()` を `strtotime()` 版に戻す | `app/Support/CsvDate.php` | `CsvDateTest::test_it_rejects_dates_that_do_not_exist` — `Failed asserting that '2026-02-30' is null.` ＋ 部屋契約の `test_an_impossible_contract_date_drops_only_that_row` |
| 2 | `checkdate()` の判定を反転（`if (checkdate(...))` で null 返し） | 同上 | `CsvDateTest` の 3 本が全部赤 |
| 3 | 物件の CSV 内重複チェックを消す | `MansionImportController::executeProperty` の `$nameTracker` 判定 | `test_a_duplicate_name_inside_the_csv_drops_only_that_row` — 2 件のはずが 3 件 |
| 4 | 部屋の DB 重複チェックを消す | `executeRoom` の `$existingRoom` 判定 | `test_a_room_number_already_in_the_database_is_an_error_row` — **UNIQUE 違反で `rollBack()` され 0 件**（Task 5 の UNIQUE が効いている証拠） |
| 5 | `total_units` 再集計のループを消す | `executeRoom` の `$touchedPropertyIds` ループ | `test_total_units_is_recalculated_after_importing_rooms` — 2 のはずが 99 |
| 6 | active 契約時の `room.status` 更新を消す | `executeRoomContract` の `MsRoom::where(...)->update(...)` | `test_an_active_room_contract_marks_the_room_occupied` — occupied のはずが vacant |
| 7 | 駐車場契約の `contract_id` を常に null にする | `executeParkingContract` の `$linkedContractId` | `test_a_linked_room_number_attaches_the_active_room_contract` |
| 8 | プレビューの `csv_data` hidden を消す | `resources/views/admin/mansion-import/_preview.blade.php` | `confirm()` の `プレビュー画面に csv_data hidden が無い` ＋ `ImportPreviewRenderTest` |
| 9 | 二重契約の警告をエラーに変える | `executeRoomContract` の `$warnings[]` → `$errors[]` | `test_a_second_active_contract_warns_but_still_imports` — 1 件のはずが 0 件 |

- [ ] **Step 3: 検出しなかった変異があれば、テストを足してから先へ進む**

⚠ **「赤になった」だけでは足りない。理由の文言を突き合わせること。**
意図と別の機構が落としている可能性を排除できない（実測で、ルート登録を 1 行だけ
コメント化した変異が**構文エラーで赤**になり、成功と誤読しかけた）。

⚠ **変異は「検査対象に入るはずの場所」へ当てること**（Bug #44）。

- [ ] **Step 4: 結果を計画ファイルに追記してコミット**

```bash
git add docs/superpowers/plans/2026-08-18-mansion-csv-import-tests.md
git commit -m "docs(plan): 賃貸マンション取込テストの変異テスト結果を記録する"
```

### Step 5: 実測結果（2026-08-18 実施 / HEAD `1403ba3f`）

**測定条件**: worktree `tenant-area-survey`（branch `mansion-import-tests`）、
ベースライン **868 tests / 5044 assertions green**。各変異とも
①`git status --porcelain` が空 ②変異を当てる ③`git diff` で**当たったこと**と
**当たり先が意図どおりであること**を確認 ④`./vendor/bin/phpunit` の**終了コード**で判定
⑤`git checkout -- <個別ファイル>` で復元、の順で実施した
（`git checkout -- .` は使わない。Bug #44）。

| # | 変異 | 検出 | 落ちたテスト | 実際の失敗メッセージ | 判定 |
|---|---|---|---|---|---|
| 1 | `CsvDate::normalize()` を `strtotime()` 版に戻す | ✅ | `CsvDateTest::test_it_rejects_dates_that_do_not_exist` / `::test_it_accepts_the_unix_epoch` / `MansionImportTest::test_an_impossible_contract_date_drops_only_that_row` | `Failed asserting that '2026-02-30' is null.` ／ `Failed asserting that null is identical to '1970-01-01'.` ／ `Failed asserting that '<!DOCTYPE html>…' contains "契約日「2026-02-30」の形式が不正です".` | 正しく検出 |
| 2 | `checkdate()` の判定を反転 | ✅ | `CsvDateTest` の **4 本全部** | `Failed asserting that null is identical to '2026-04-01'.` ／ `Failed asserting that '2026-13-01' is null.` ／ `Failed asserting that '2026-02-30' is null.` ／ `Failed asserting that null is identical to '1970-01-01'.` | 正しく検出 |
| 3 | 物件の CSV 内重複チェックを消す | ✅ | `test_a_duplicate_name_inside_the_csv_drops_only_that_row` | `Failed asserting that '<!DOCTYPE html>…' contains "物件名「同名」がCSV内で重複しています".`（**プレビュー**の assert、:287） | 正しく検出 |
| 4 | 部屋の DB 重複チェックを消す | ✅ | `test_a_room_number_already_in_the_database_is_an_error_row` | `Failed asserting that '<!DOCTYPE html>…' contains "の部屋「101」は既に登録されています".`（**プレビュー**の assert、:374） | 正しく検出（ただし理由は計画の予測と違う。下記 ⚠1） |
| 5 | `total_units` 再集計のループを消す | ✅ | `test_total_units_is_recalculated_after_importing_rooms` | `Failed asserting that 99 is identical to 2.` | 正しく検出 |
| 6 | active 契約時の `room.status` 更新を消す | ✅ | `test_an_active_room_contract_marks_the_room_occupied` | `Failed asserting that two strings are identical. -'occupied' +'vacant'` | 正しく検出 |
| 7 | 駐車場契約の `contract_id` を常に null にする | ✅ | `test_a_linked_room_number_attaches_the_active_room_contract` | `Failed asserting that null is identical to 1.` | 正しく検出 |
| 8 | `csv_data` hidden を `csv_data_x` に改名 | ✅ | `MansionImportTest` の **7 failures ＋ 12 errors** | 主信号: `Failed asserting that null matches expected '物件インポート完了: 3件を登録しました'.` | 検出（巻き添えが多い。下記 ⚠2） |
| 8b | `confirmed` hidden を `confirmed_x` に改名 | ✅ | `test_property_round_trip_creates_the_row` | `「インポート実行」フォームに confirmed hidden が無い（tab=property）` / `Failed asserting that an array has the key 'confirmed'.` | 正しく検出 |
| 8c | フォームの `action` を別タブへ向ける | ✅ | 同上 | `「インポート実行」フォームの action が別の endpoint を指している（tab=property）` / `-'http://localhost/admin/mansion-import/property' +'…/tenant'` | 正しく検出 |
| 8d | `@csrf` を消す | ✅ | 同上 | `「インポート実行」フォームに @csrf が無い（tab=property）` / `Failed asserting that an array has the key '_token'.` | 正しく検出 |
| 9 | 二重契約の**警告をエラーに変える** | ❌ | **なし** | — （**全 868 テスト green**） | **検出せず**。下記 ⚠3 |
| 10 | テスト用スキーマの UNIQUE 3 本を消す ＋ 変異 4 | ✅ | `test_a_room_number_already_in_the_database_is_an_error_row` | 変異 4 単体と**同じ** `…contains "の部屋「101」は既に登録されています".` | 計画の予測と逆。下記 ⚠1 |
| 10b | UNIQUE 3 本だけ消す（変異なし） | ❌ | **なし** | — （**全 868 テスト green**） | UNIQUE は現状どのテストも検出していない。⚠1 |

#### ⚠1 変異 4 を捕まえているのは UNIQUE 制約ではなく、プレビューの assert だった

計画は「変異 4 → UNIQUE 違反 → `rollBack()` → 0 件」で赤くなると予測し、
それを Task 5 の UNIQUE が load-bearing である証拠と位置づけていた。**実測は違う。**

`test_a_room_number_already_in_the_database_is_an_error_row` は
**先に** `$preview->assertSee('の部屋「101」は既に登録されています')`（:374）を見る。
これはコントローラの `$existingRoom` チェックが出すメッセージ＝**アプリのロジックだけ**で決まり、
DB 制約は一切関与しない。変異 4 はまさにそのチェックを消すので、
**UNIQUE の有無に関係なく**この行で落ちる。テストはそこで停止するため、
末尾の件数 assert（UNIQUE が効く場所）は**一度も実行されていない**。

実測で裏を取った:

- 変異 10（UNIQUE 3 本を消して変異 4 を当てる）→ **赤のまま**。しかも失敗メッセージが変異 4 単体と 1 文字も違わない
- 変異 10b（UNIQUE 3 本だけ消す）→ **868 tests green**

⇒ **現状、この 3 本の UNIQUE を検出しているテストは 1 本も無い。**
本番 DDL への忠実さという意味は残る（テスト用スキーマが本番と乖離すると
Bug「migration vs live schema drift」の再来になる）が、
**「Task 5 の UNIQUE が効いている証拠」としては成立していない**ので、
計画のその記述は誤りとして訂正しておく。

#### ⚠2 変異 8 は検出するが、落ち方が読みにくい

`csv_data` を改名すると往復 POST が CSV を運ばなくなるため、
**19 本が赤**になるが内訳は **7 failures ＋ 12 errors**。
12 件は `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`
＝ 取込が 0 件で終わった後に seed ヘルパの `MsProperty::sole()` が落ちる**巻き添え**であって、
`csv_data` を名指ししてはいない。原因を名指しする主信号は
`Failed asserting that null matches expected '物件インポート完了: 3件を登録しました'.` の側。

⚠ **`ImportPreviewRenderTest` はこの変異で緑のまま**だった（実測 `OK (2 tests, 6 assertions)`）。
同テストはプレビューが 200 を返すことと view へ `'errors'` を渡していないことしか見ておらず、
**往復はしない**。計画の期待欄が `ImportPreviewRenderTest` も赤になると書いていたのは誤り。
往復を守っているのは `MansionImportTest::parseImportForm()` だけである。

#### ⚠3 【未検出】変異 9 — 警告をエラーに変えても全 868 テストが緑

**`$warnings[]` → `$errors[]` に変えても、`./vendor/bin/phpunit` は
`OK (868 tests, 5044 assertions)` を返す（終了コード 0）。** 変異が当たっていることは
`git diff` で確認済み（1 行 1 箇所）。

原因は 2 つ重なっている:

1. **`$errors[]` に積む行に `continue` が無い。** よってエラー扱いにしても
   **その行はそのまま取り込まれる**。取込件数もレコード数も変わらないので、
   `'部屋契約インポート完了: 1件を登録しました'` も `MsContract::count() === 2` も**真のまま**
2. **`assertSee('には既に契約中の入居者がいます', false)` は、その文字列が
   警告ブロックに出ていてもエラーブロックに出ていても等しく一致する。**
   つまり「警告として出た」ことを**一度も確かめていない**

`test_a_second_active_contract_warns_but_still_imports` の docblock は
「⚠ 警告（`warnings`）とエラー（`rowErrors`）は別物。**警告をエラーに変える変異で赤くなるよう**、
『警告が出ること』と『それでも入ること』を対で見る。」と書いているが、
**その保証は実測で成立していない**。Bug #43 / #46 / #49 と同型
（同じ文字列が複数の役割で画面に出るため、素の `assertSee` が false-pass する）。

⚠ **本件は本タスクでは修正しない**（Task 13 は測定であって、
未検出を埋めてテストを緑に見せる作業ではない）。**提案**は次のとおり:

コントローラは `rowErrors` と `warnings` を**別々の view データ**として渡している
（`MansionImportController` の 976/977 行・1217/1218 行）ので、
文字列一致ではなく**役割ごとに件数で**見れば区別できる。

```php
$preview = $this->preview('room-contract', $csv);
$preview->assertSee('には既に契約中の入居者がいます', false);

// ⚠ 文字列は警告ブロックにもエラーブロックにも出るので、
//    「警告として出たこと」を役割で固定する（実測: これが無いと
//    $warnings[] → $errors[] の変異が 868 テスト全部を素通りする）
$this->assertCount(1, $preview->viewData('warnings'));
$this->assertCount(0, $preview->viewData('rowErrors'));
```

⚠ この 2 行を足したら、**再度 `$warnings[]` → `$errors[]` の変異を当てて
赤になることを実測すること**（足しただけでは「守られている」証拠にならない）。

#### まとめ

- 実施 **13 通り**（1〜9 ＋ 8b/8c/8d ＋ 10/10b）
- **正しく検出 11 通り**（1・2・3・4・5・6・7・8b・8c・8d、および 8 は巻き添え付きで検出）
- **未検出 1 通り**（変異 9）＝ 警告とエラーの区別が固定されていない
- **計画の予測が誤りだった箇所 2 件**（変異 4 の赤くなる理由と UNIQUE の位置づけ／
  変異 8 における `ImportPreviewRenderTest` の関与）
- 測定後、`git status --porcelain` が空であることと
  **868 tests / 5044 assertions green** に戻っていることを確認済み

---

## Task 14: 判明した罠を RULES.md に記録する

**Files:**
- Modify: `docs/RULES.md`
- Modify: `CLAUDE.md`（件数と、Top traps に値するものがあれば）

- [ ] **Step 1: Bug #54 として追記する**

最低限、次を含める（Task 6 / Task 5 / Task 13 の実測結果で肉付けする）:

- **症状**: 打鍵ミス 1 行（`2026-02-30`）で取込全体が失敗し、画面には生の SQL エラーが出る
- **原因**: `strtotime()` は存在しない日付を繰り上げて成功を返す。逆に timezone が UTC のとき
  `strtotime('1970-01-01') === 0` が falsy で、その 1 日だけ拒否される
- **対策**: `checkdate()`。`App\Support\CsvDate` に 1 本化（両取込コントローラが逐語コピーで
  持っていた 4 本を Support へ寄せた。`getNextPropertyCodeNum` だけは実装が違うので残した）
- ⚠ **テスト用スキーマに本番の UNIQUE が無いと、重複判定の欠陥が原理的に検出できない**
  （Bug #40 と同型。`CreatesMansionSchema` に 3 本追加）
- ⚠ **往復テストは画面が描画した hidden を送り返す**（自前で base64 を組むと画面が壊れても緑。Bug #47）

- [ ] **Step 2: 件数を更新する**

```bash
grep -n "全 53 件の詳細バグカタログ" CLAUDE.md
```
→ 54 に更新

- [ ] **Step 3: コミット**

```bash
git add docs/RULES.md CLAUDE.md
git commit -m "docs(rules): strtotime で日付を検証する罠を Bug #54 に追加"
```

---

## Task 15: 仕上げ

- [ ] **Step 1: 全テストを回す**

Run: `./vendor/bin/phpunit`
Expected: OK — 830 + 約 35 本

- [ ] **Step 2: コンパイル済みビューを lint する**

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` が 1 件も出ない

- [ ] **Step 3: 13.x へ FF-merge する**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only mansion-import-tests
```

- [ ] **Step 4: 新規クラスの autoload を main repo で通す**

⚠ **`app/Support/` に 4 クラス増えているので必要。**
⚠ **worktree から実行しない**（autoloader の `$baseDir` に worktree パスが焼き込まれ、
main repo の Apache が worktree を参照する事故になる）。

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

- [ ] **Step 5: デプロイの可否をユーザーに確認する**

⚠ **`./deploy.sh` はユーザーの明示承認が必要。** DB 変更は無いので `deploy.sh` のみで足りる。
承認が出たら実行し、本番ブラウザで取込を 1 本通して確認する
（**全項目が空欄の行**にすればプレビューは描画されるがレコードは作られない）。
