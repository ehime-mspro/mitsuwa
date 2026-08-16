# 周辺ビル調査（テナント管理）第1段 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 自社ビル周辺のビル調査（ビル / 調査回 / 入居テナント）をデータベース化し、一覧・詳細・登録編集・Excel 取込・座標一括取得まで揃えて、既存 Excel 50 棟以上を流し込んで運用開始できる状態にする。

**Architecture:** `area_*` 3 テーブル（raw SQL ＋ SQLite テスト用 migration の対）。空室率は `App\Support\VacancyRate` の 1 箇所に集約し、一覧の絞り込み・並び替えもそこを通す。画面はテナント管理配下 `/tenant/area-buildings` に既存意匠で追加し、調査回とテナントは Ajax ではなく別画面の CRUD にする。地図は登録編集フォームでボタンを押したときだけ生成し、詳細は「Google マップで開く」リンクのみ。

**Tech Stack:** Laravel 12 / PHP 8.3（本番） / MySQL 8（本番）・SQLite in-memory（テスト） / Blade + Alpine.js 3 / Tailwind v4 / SheetJS 0.18.5（CDN） / Google Maps JavaScript API（Geocoder のみ）

**設計書（正）:** `docs/superpowers/specs/2026-08-12-tenant-area-building-survey-design.md`
**対象範囲:** 設計書 §9「第1段」のみ。第2段（一覧地図 / `properties` への緯度経度 / エリア集計）は対象外。

---

## 0. 作業環境（着手前に確認）

- 作業ディレクトリは既存 worktree **`/Users/masanori/site/manage/.claude/worktrees/tenant-area-survey`**（branch `tenant-area-survey`）。新しく作らない。
- worktree には `vendor` が無い。テストを走らせる前に worktree 内で 1 度だけ:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-area-survey && composer install
```

- テスト実行はすべて worktree のルートから:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-area-survey && vendor/bin/phpunit
```

- `artisan` を使う場合は `.env` が無いので `APP_KEY` を環境変数で渡す（`php artisan test` / `pest` はこのリポジトリに無い。`vendor/bin/phpunit` を使う）。
- コミットは commit-commands の `/commit`。`git push` はユーザー明示指示があるまで行わない。

---

## 1. 設計書からの補足・逸脱（着手前に必ず読む）

設計書を実装可能な粒度まで落とす過程で判明した点。**すべてこのプランの側を正とする。**

| # | 設計書の記述 | 実態 / このプランの扱い |
|---|---|---|
| 1 | §5.1 のルート表 | **座標一括取得（§7.4）のルートが表に無い。** `POST /tenant/area-buildings/geocode` を追加する（`role:executive,manager`）。 |
| 2 | §11-1「`round` や float 除算に戻すと赤になる値を置く」 | **float 除算に戻して赤になる値は、この規模では存在しない。** IEEE754 の除算は正しく丸められるので、真の商が整数なら `floor($v * 1000 / $t)` は `intdiv` と一致する。ズレるには総区画数が概ね 9×10^12 を超える必要がある（`(N·t − 1000v)/t < N·2⁻⁵³` を満たす `t`）。→ **値テストは `round` 戻しを検出する役割に限定**し、`intdiv` 経路は**構造テスト**（コメント除去後に `intdiv(` があり `round(` が無い）で固定する。 |
| 3 | §11-4「ページ送りでフィルタが維持される」 | 本機能は `?vacancy=` の**既定が「全て」**なので、Bug #31 の発火条件②（既定が絞り込み）を満たさない。→ `appends(array_map(...))` を `withQueryString()` に戻しても**緑のまま**になる。テストは値が空にならない `keyword` で組み、**`appends` を丸ごと外す変異**で赤になることを確認する。`?? ''` のマッピングは将来「既定が絞り込み」のフィルタが増えたときの保険として残すが、このテストスイートでは区別できないことを明記する。 |
| 4 | §5.3「N+1 対策: 最新 1 件だけを引くサブクエリ」 | サブクエリで最新調査回の 4 列を引くところまでは設計どおり。ただし**空室率の絞り込み・並び替えは PHP 側で行う**。SQL に率の式を書くと `VacancyRate` と二重実装になり、MySQL と SQLite で整数除算の意味も違う（Bug #41）。→ 絞り込み後の全件を一度ロードして PHP で並べ、`LengthAwarePaginator` を手で組む（`ProcurementListService` と同じ流儀）。**棟数が数千を超えたら見直す**。 |
| 5 | §5.5「新規登録時のみ 1 回目の調査も入力」 | 調査の所見フィールド名は **`survey_notes`**（ビル自身の `notes` と衝突するため）。調査専用画面のほうは `notes` のまま。 |
| 6 | §10.1「画面ごとに語が変わるキーは第3引数で上書き」 | **`JapaneseValidationMessagesTest::test_every_validated_field_has_a_japanese_attribute_label` はグローバルの `attributes` しか見ない**（`tests/Feature/JapaneseValidationMessagesTest.php:113`）。→ 第3引数で上書きするキーも**グローバルに存在させる必要がある**。 |
| 7 | §3.2「`User` は SoftDeletes なので `withTrashed()`」 | **設計書が正しい。** `app/Models/User.php:16` が `use HasFactory, Notifiable, SoftDeletes;`、`:58` に `'deleted_at' => 'datetime'`。CLAUDE.md の「`User` モデルに `deleted_at` 列なし」は**現在は誤り**（別途 CLAUDE.md を直す価値がある。本プランでは触らない）。 |
| 8 | §6.0「地図を生成する箇所は 2 つだけ」 | 一覧には**座標一括取得のために Google Maps JS の bootstrap を読み込む**。課金対象の Dynamic Maps SKU は `new google.maps.Map()` の実行に対して発生し、bootstrap の読み込みだけでは発生しない（Geocoder は Geocoding SKU で 1 棟 1 回）。→ §6.0 と矛盾しない。ただし**座標未取得が 0 件のときは `<script>` 自体を出さない**ことをテストで固定する。 |

---

## 2. ファイル構成

### 新規作成

| ファイル | 責務 |
|---|---|
| `database/sql/2026-08-12-create-area-building-tables.sql` | 本番 MySQL 用 DDL |
| `database/migrations/2026_08_12_000001_create_area_building_tables.php` | 上記のミラー（SQLite テスト用） |
| `app/Support/VacancyRate.php` | 空室率の唯一の計算元（純粋 static） |
| `app/Enums/AreaTenantStatus.php` | 営業 / 空き / 不明 ＋ Excel エイリアス正規化 |
| `app/Models/AreaBuilding.php` | ビル（SoftDeletes）。ビル名正規化・座標未取得の抽出 |
| `app/Models/AreaBuildingSurvey.php` | 調査回。`surveyed_month` の月初正規化 |
| `app/Models/AreaBuildingTenant.php` | 入居テナント（現況リスト） |
| `app/Services/Tenant/AreaBuildingListService.php` | 一覧のクエリ・絞り込み・並び替え・ページャ |
| `app/Http/Controllers/Tenant/AreaBuildingController.php` | index / create / store / show / edit / update / destroy / storeCoordinates |
| `app/Http/Controllers/Tenant/AreaBuildingSurveyController.php` | 調査回の create / store / edit / update / destroy |
| `app/Http/Controllers/Tenant/AreaBuildingTenantController.php` | テナントの create / store / edit / update / destroy |
| `app/Http/Controllers/Tenant/AreaBuildingImportController.php` | Excel 取込 form / execute |
| `resources/views/tenant/area-buildings/index.blade.php` | 一覧＋フィルタ＋座標一括取得 |
| `resources/views/tenant/area-buildings/show.blade.php` | 詳細（乖離警告 / マップリンク / 調査履歴 / テナント一覧） |
| `resources/views/tenant/area-buildings/create.blade.php` / `edit.blade.php` / `_form.blade.php` | ビル登録編集（地図＋ジオコーディング） |
| `resources/views/tenant/area-buildings/surveys/create.blade.php` / `edit.blade.php` / `_form.blade.php` | 調査回 |
| `resources/views/tenant/area-buildings/tenants/create.blade.php` / `edit.blade.php` / `_form.blade.php` | テナント |
| `resources/views/tenant/area-buildings/import.blade.php` | Excel 取込（SheetJS） |
| `tests/Feature/Tenant/AreaBuildingTestCase.php` | Feature テスト共通のアクター生成（抽象クラス。`*Test.php` でないので PHPUnit は拾わない） |
| `tests/Unit/Support/VacancyRateTest.php` ほか 10 本 | 各タスク参照 |

### 変更

| ファイル | 変更内容 |
|---|---|
| `routes/web.php` | `tenant` プレフィックス配下に 20 ルート追加（設計 §5.1 の 19 本 ＋ 座標一括取得 1 本）|
| `resources/views/layouts/partials/sidebar.blade.php` | テナント管理グループに 1 項目（**同じブロックが 68 行目付近と 344 行目付近の 2 箇所にある。両方直す**） |
| `lang/ja/validation.php` | `attributes` に 11 キー追加 |

### タスク一覧

| # | タスク | 主な成果物 |
|---|---|---|
| 1 | 空室率ヘルパー | `App\Support\VacancyRate` |
| 2 | DB スキーマ | raw SQL ＋ migration ＋ drift 検出テスト |
| 3 | Enum | `AreaTenantStatus`（Excel エイリアス正規化つき） |
| 4 | モデル 3 本 | 月初正規化 / 名前正規化 / 座標未取得の抽出 |
| 5 | 日本語バリデーション項目名 | `lang/ja/validation.php` に 11 キー |
| 6 | 一覧 | サービス / コントローラ / ビュー / ルート / サイドバー |
| 7 | 詳細 | 乖離警告 / マップリンク / 調査履歴 / テナント一覧 |
| 8 | ビル登録・編集・削除 | 地図＋ジオコーディング / 初回調査の同時登録 |
| 9 | 調査回 CRUD | 別画面 / 同一年月の差し戻し / 所有権チェック |
| 10 | テナント CRUD | 別画面 / 保存して続けて登録 / 所有権チェック |
| 11 | Excel 取込 | SheetJS ＋ サーバ確定（ビル＋調査 / テナント明細） |
| 12 | 座標の一括取得 | 未設定のみ / 1 棟 1 回 / 上限 200 |
| 13 | 最終検証と本番反映 | 走査検査 / view:cache lint / デプロイ手順 |

各タスクは独立してコミットでき、その時点でテストが緑になる。

---

## Task 1: 空室率ヘルパー `App\Support\VacancyRate`

**Files:**
- Create: `app/Support/VacancyRate.php`
- Test: `tests/Unit/Support/VacancyRateTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/VacancyRateTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\VacancyRate;
use PHPUnit\Framework\TestCase;

/**
 * 空室率 = (空き + 不明) × 100 ÷ (営業 + 空き + 不明)、1/10 % 単位の切り捨て。
 *
 * ⚠ float 除算に戻して赤になる値は、この規模では存在しない（プラン §1-2）。
 *    値テストが守るのは「不明を空きに含めること」「切り捨てであること」「総数 0 で null」の 3 点。
 *    intdiv 経路そのものは test_implementation_uses_integer_division が守る。
 */
class VacancyRateTest extends TestCase
{
    /** 切り捨て。⚠ round に戻すと 66.7 / 28.6 になって赤になる値を明示的に置いている */
    public function test_truncates_to_one_tenth_percent(): void
    {
        // 2 ÷ 3 = 66.666…% → 66.6（round なら 66.7）
        $this->assertSame(66.6, VacancyRate::percent(1, 2, 0));

        // 2 ÷ 7 = 28.571…% → 28.5（round なら 28.6）
        $this->assertSame(28.5, VacancyRate::percent(5, 2, 0));
    }

    /** 「不明」は空きとして数える。⚠ 不明を除外 or 営業に寄せると 0.0 になって赤になる */
    public function test_unknown_counts_as_vacant(): void
    {
        $this->assertSame(20.0, VacancyRate::percent(8, 0, 2));
        $this->assertSame(50.0, VacancyRate::percent(5, 3, 2));
    }

    public function test_returns_null_when_there_are_no_units(): void
    {
        $this->assertNull(VacancyRate::percent(0, 0, 0));
    }

    public function test_boundaries(): void
    {
        $this->assertSame(0.0, VacancyRate::percent(3, 0, 0));
        $this->assertSame(100.0, VacancyRate::percent(0, 1, 0));
        $this->assertSame(100.0, VacancyRate::percent(0, 0, 2));
    }

    public function test_label_formats_one_decimal_and_dashes_when_unsurveyed(): void
    {
        $this->assertSame('66.6%', VacancyRate::label(1, 2, 0));
        $this->assertSame('0.0%', VacancyRate::label(3, 0, 0));
        $this->assertSame('100.0%', VacancyRate::label(0, 1, 0));
        $this->assertSame('—', VacancyRate::label(0, 0, 0));
    }

    /**
     * 経路を構造で固定する。
     *
     * ⚠ コメントを落としてから検索すること。この判定を消すと、クラスの docblock に
     *    書いた「round は使わない」の 'round' に一致して、実装を round に戻しても
     *    緑のまま通る（Bug #42 ②と同型）。
     */
    public function test_implementation_uses_integer_division(): void
    {
        $src = $this->sourceWithoutComments(__DIR__ . '/../../../app/Support/VacancyRate.php');

        $this->assertStringContainsString('intdiv(', $src, 'intdiv による整数演算になっていない');
        $this->assertStringNotContainsString('round(', $src, '丸めに round を使っている');
        $this->assertDoesNotMatchRegularExpression('/\bfloor\s*\(/', $src, '丸めに floor を使っている');
    }

    /** PHP トークナイザでコメント / docblock を落としたソースを返す */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter VacancyRateTest
```

Expected: FAIL — `Class "App\Support\VacancyRate" not found`

- [ ] **Step 3: 実装を書く**

`app/Support/VacancyRate.php`:

```php
<?php

namespace App\Support;

/**
 * 周辺ビル調査の空室率ヘルパー。
 *
 * 空室率(%) = (空き + 不明) × 100 ÷ (営業 + 空き + 不明)
 * 「不明」は空きとして扱う（現地で判断できなかった区画は稼働していない前提で見る）。
 *
 * 丸めは 1/10 % 単位の切り捨て。整数演算だけで行う。
 *
 * ⚠ 四捨五入に戻さないこと。2 ÷ 3 が 66.6% でなく 66.7% に、
 *   2 ÷ 7 が 28.5% でなく 28.6% になる（VacancyRateTest がこの 2 値で固定している）。
 *
 * ⚠ この計算はここ 1 箇所だけに置く。一覧・詳細・取込プレビューが同じ式を
 *   別々に持つと、片方だけ直す事故が起きる（Bug #41）。
 *   SQL 側で率を計算するのも禁止（MySQL と SQLite で整数除算の意味が違う）。
 */
class VacancyRate
{
    /** 1/10 % 単位で扱うための係数（100% × 10） */
    private const SCALE = 1000;

    /**
     * 空室率（%）。総区画数が 0 のときは null（ゼロ除算＝未調査）。
     */
    public static function percent(int $operating, int $vacant, int $unknown): ?float
    {
        $total = $operating + $vacant + $unknown;

        if ($total <= 0) {
            return null;
        }

        return intdiv(($vacant + $unknown) * self::SCALE, $total) / 10;
    }

    /**
     * 画面表示用のラベル。未調査は「—」。
     */
    public static function label(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::percent($operating, $vacant, $unknown);

        return $rate === null ? '—' : number_format($rate, 1) . '%';
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter VacancyRateTest
```

Expected: PASS（6 tests）

- [ ] **Step 5: 変異テスト — 3 通りで赤になることを実測する**

`git stash` は使わず、手で書き換えて戻すこと。**毎回 `git diff` が非空であることを確認する**（変異が当たっていないのに「検出しない」と誤読する事故を防ぐ。Bug #44）。

| # | 変異 | 期待 |
|---|---|---|
| 1 | `intdiv((...) * self::SCALE, $total) / 10` → `round(($vacant + $unknown) * 100 / $total, 1)` | `test_truncates_to_one_tenth_percent` と `test_implementation_uses_integer_division` が赤 |
| 2 | `($vacant + $unknown)` → `$vacant` | `test_unknown_counts_as_vacant` が赤 |
| 3 | `$total <= 0` の early return を削除 | `test_returns_null_when_there_are_no_units` が赤（DivisionByZeroError） |

さらに **コメント除去が機能していることの確認**（Bug #42 ②）:

⚠ **2026-08-13 実測で修正。** 当初この欄には「変異 1 を当てたまま `sourceWithoutComments()` を
`file_get_contents()` に差し替えると `test_implementation_uses_integer_division` が緑に戻る」と
書いていたが、**再現しない**。理由: 変異 1 は `round(` を**実コード**に入れるので、コメント除去の
有無にかかわらず `assertStringNotContainsString('round(', ...)` が失敗する。Bug #42 ② の
false-pass は「**docblock 側に識別子 literal がある**」ときにだけ起きる条件で、この
`VacancyRate.php` の docblock は日本語（「四捨五入」「丸め」）だけで `round(` を含まない。

正しい確認手順（実装者と仕様レビュアーの双方が実測済み）:

- [ ] 実装は**正しいまま**、docblock に `round(` を含む行を一時的に足す（例: `⚠ 丸めに round() を使わない。`）
- [ ] `test_implementation_uses_integer_division` が **緑**であることを確認する（コメント除去が効いている）
- [ ] `sourceWithoutComments()` の中身を `return file_get_contents($path);` に差し替える
- [ ] 同じテストが **赤になる**ことを確認する（docblock の `round(` を拾うため）
- [ ] 両方を元に戻し、再度 PASS を確認する

⚠ 現在の `VacancyRate.php` に対しては、コメント除去は**今この瞬間は inert**（docblock に
識別子 literal が無いため）。将来 docblock に英語の識別子を書き足したときの予防線として残す。
**この形の構造テストを他のタスクで書くときも、「除去が今 load-bearing か」と
「除去の仕組みが動くか」を混同しないこと。**

- [ ] **Step 6: コミット**

```bash
git add app/Support/VacancyRate.php tests/Unit/Support/VacancyRateTest.php
```

`/commit` で `feat(tenant): 周辺ビル調査の空室率ヘルパーを追加` 相当のメッセージを作る。

---

## Task 2: DB スキーマ（raw SQL ＋ migration）

**Files:**
- Create: `database/sql/2026-08-12-create-area-building-tables.sql`
- Create: `database/migrations/2026_08_12_000001_create_area_building_tables.php`
- Test: `tests/Feature/Tenant/AreaBuildingSchemaTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 3 テーブルが migration で作られることと、raw SQL 側と列が一致していることを固定する。
 *
 * ⚠ 本番は database/sql/2026-08-12-create-area-building-tables.sql を直接流す。
 *   migration はテスト専用のミラーで、片方だけ直すと SQLite テストだけが落ちる drift になる。
 *   test_raw_sql_and_migration_declare_the_same_columns がその drift を拾う。
 */
class AreaBuildingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('area_buildings'));
        $this->assertTrue(Schema::hasTable('area_building_surveys'));
        $this->assertTrue(Schema::hasTable('area_building_tenants'));

        $this->assertTrue(Schema::hasColumns('area_buildings', [
            'id', 'name', 'address', 'latitude', 'longitude', 'total_floors',
            'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('area_building_surveys', [
            'id', 'area_building_id', 'surveyed_month', 'operating_count',
            'vacant_count', 'unknown_count', 'surveyed_by', 'notes', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('area_building_tenants', [
            'id', 'area_building_id', 'floor', 'room_number', 'name', 'industry',
            'status', 'confirmed_on', 'moved_out_on', 'notes', 'created_at', 'updated_at',
        ]));
    }

    /** 調査回は SoftDeletes を持たない（設計 §3.2: 調査回は物理削除） */
    public function test_surveys_and_tenants_are_hard_deleted(): void
    {
        $this->assertFalse(Schema::hasColumn('area_building_surveys', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('area_building_tenants', 'deleted_at'));
    }

    /** 同じビルの同じ調査年月は 1 件だけ */
    public function test_same_building_and_month_cannot_be_inserted_twice(): void
    {
        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = [
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('area_building_surveys')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('area_building_surveys')->insert($row);
    }

    /**
     * area_buildings を削除すると、紐づく調査回・入居テナントの行も削除される
     * （CASCADE。設計 §3.2 / §3.3）。
     *
     * ⚠ area_buildings は Task 4 で Eloquent モデルに SoftDeletes が付く想定。Eloquent の
     *   delete() を使うと UPDATE ... SET deleted_at = ... になるだけで実際の DELETE 文が
     *   発行されず CASCADE が発火しない（テストは書いたが何も検証していない状態になる）。
     *   DB::table(...)->delete() で物理削除して検証すること。
     */
    public function test_deleting_a_building_cascades_to_its_surveys_and_tenants(): void
    {
        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $surveyId = DB::table('area_building_surveys')->insertGetId([
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tenantId = DB::table('area_building_tenants')->insertGetId([
            'area_building_id' => $buildingId,
            'status' => 'operating',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('area_buildings')->where('id', $buildingId)->delete();

        $this->assertFalse(DB::table('area_building_surveys')->where('id', $surveyId)->exists());
        $this->assertFalse(DB::table('area_building_tenants')->where('id', $tenantId)->exists());
    }

    /**
     * users を削除すると、area_buildings.created_by / area_building_surveys.surveyed_by は
     * NULL になる（SET NULL。設計 §3.2 / §3.3）。登録者・調査者の情報が失われても、
     * 調査データそのものは残す設計。
     *
     * ⚠ 上のテストと同じ理由で DB::table(...)->delete() を使うこと
     *   （Eloquent の delete() だと SoftDeletes で物理削除が発生しない）。
     */
    public function test_deleting_a_user_nulls_out_created_by_and_surveyed_by(): void
    {
        $user = User::factory()->create();

        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $surveyId = DB::table('area_building_surveys')->insertGetId([
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'surveyed_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('area_buildings')->where('id', $buildingId)->value('created_by'));
        $this->assertNull(DB::table('area_building_surveys')->where('id', $surveyId)->value('surveyed_by'));
    }

    /**
     * raw SQL と migration が同じ列を宣言していること。
     *
     * ⚠ 手書きの期待リストと突き合わせてはいけない。それだと「リストに書き忘れた列」が
     *   永遠に検査対象に入らず、migration にだけ列が増えても緑のまま通る
     *   （CLAUDE.md Top trap #13 / Bug #45 ①）。
     *   migration 側は Schema::getColumnListing() で「実際に作られた列」を取り、
     *   raw SQL 側は DDL を機械的にパースして、集合として双方向に比較する。
     *
     * ⚠ このテストが見ているのは列名の集合だけ。型・NULL 可否・デフォルト値・
     *   インデックス定義の drift は検出しない（型マッピング層を追加するとそれ自体が
     *   壊れやすい新しい依存になるため見送り。2026-08-14 コード品質レビュー Important #3）。
     */
    public function test_raw_sql_and_migration_declare_the_same_columns(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-08-12-create-area-building-tables.sql'));

        $tables = ['area_buildings', 'area_building_surveys', 'area_building_tenants'];
        $checked = 0;

        foreach ($tables as $table) {
            $fromMigration = Schema::getColumnListing($table);
            $fromRawSql    = $this->columnsInRawSql($sql, $table);

            sort($fromMigration);
            sort($fromRawSql);

            // 走査が空振りして「両方とも空 = 一致」で緑になる事故を防ぐ
            $this->assertNotEmpty($fromRawSql, "{$table} の raw SQL から列を 1 つも拾えていない");
            $this->assertGreaterThanOrEqual(10, count($fromMigration), "{$table} の migration 側の列が少なすぎる");

            $this->assertSame(
                $fromRawSql,
                $fromMigration,
                "{$table} の raw SQL と migration で列が食い違っている（drift）"
            );

            $checked += count($fromRawSql);
        }

        $this->assertGreaterThanOrEqual(33, $checked, 'raw SQL の走査が機能していない（空振り防止）');
    }

    /**
     * raw SQL の DDL から「実際の列名」を機械的に拾う。
     *
     * `CREATE TABLE IF NOT EXISTS <table> (` の直後の `(` から対応する `) ENGINE=InnoDB` までを
     * 本文として切り出し、括弧の深さとシングルクォート状態を追いながらトップレベルの
     * カンマで分割する。
     *
     * ⚠ テーブル名は境界付き正規表現で探す。`strpos` の前方一致だと、
     *   `area_buildings_history` のように同じ接頭辞を持つ架空テーブルを先に誤って
     *   拾ってしまう（設計書 §12 は第2段での area_* テーブル追加を示唆しており、
     *   共通接頭辞が増える前提のため）。
     * ⚠ 本文を行単位で分割してはいけない。1 行に複数カラムを圧縮された drift
     *   （`total_floors INT NULL, extra_field VARCHAR(10) NULL,` のように既存行へ
     *   追記された新しい列）を見逃す ── それ自体がこのテストの検出対象。
     *   DECIMAL(10,7) の括弧内カンマや COMMENT '...' の文字列内カンマで誤って
     *   分割しないよう、括弧の深さとクォート状態を持つスキャナーでトップレベルの
     *   カンマだけを区切りに使う。
     */
    private function columnsInRawSql(string $sql, string $table): array
    {
        $found = preg_match(
            '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\(/',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found, "{$table} が見つからない");

        $openParenPos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $end = strpos($sql, ') ENGINE=InnoDB', $openParenPos);
        $this->assertNotFalse($end, "{$table} の終端が見つからない");

        $body = substr($sql, $openParenPos + 1, $end - ($openParenPos + 1));

        return $this->splitTopLevelColumns($body);
    }

    /** 括弧の深さとシングルクォート状態を追いながら、トップレベルのカンマで分割する。 */
    private function splitTopLevelColumns(string $body): array
    {
        $notColumns = ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN'];
        $columns = [];
        $segment = '';
        $depth = 0;
        $inQuote = false;

        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inQuote) {
                $segment .= $char;
                if ($char === "'") {
                    $inQuote = false;
                }
                continue;
            }

            if ($char === "'") {
                $inQuote = true;
                $segment .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $segment .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;
                $segment .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $columns[] = $this->firstColumnToken($segment, $notColumns);
                $segment = '';
                continue;
            }

            $segment .= $char;
        }
        $columns[] = $this->firstColumnToken($segment, $notColumns);

        return array_values(array_filter($columns, static fn ($column) => $column !== null));
    }

    /**
     * 断片の先頭トークンを列名候補として取り出す。PRIMARY KEY / INDEX / UNIQUE KEY /
     * CONSTRAINT ... FOREIGN KEY のような列ではない断片は、先頭トークンで除外する
     * （断片全体への部分一致にすると created_by のような列名を誤って落とすため）。
     */
    private function firstColumnToken(string $segment, array $notColumns): ?string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return null;
        }

        if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $segment, $m)) {
            return null;
        }

        $firstToken = $m[1];
        if (in_array(strtoupper($firstToken), $notColumns, true)) {
            return null;
        }

        return $firstToken;
    }
}
```

> **2026-08-14 追記（仕様レビューで訂正）**: 当初は raw SQL 側だけを手書きの期待リストと
> 照合しており、migration 側の drift（列が消える／余分に増える）を検出できなかった。
> レビューで実測して判明し、`Schema::getColumnListing()` と DDL の機械的パースを
> 双方向に比較する形へ書き直した（commit は Task 2 のコミット履歴を参照）。
>
> **2026-08-14 追記2（コード品質レビューで訂正）**: `columnsInRawSql()` を行単位で
> 分割していたため、1 行に複数カラムを圧縮された drift（例:
> `total_floors INT NULL, extra_field VARCHAR(10) NULL,`）を見逃していた。加えて
> テーブル名の切り出しが `strpos` の前方一致だったため、`area_buildings_history` の
> ような同じ接頭辞を持つ架空テーブルを誤って拾いうる状態だった。括弧の深さと
> シングルクォート状態を追うスキャナー（トップレベルのカンマで分割）＋境界付き
> 正規表現によるテーブル名検索に書き直した。あわせて CASCADE / SET NULL の FK 挙動を
> 固定する回帰テスト 2 本（`test_deleting_a_building_cascades_to_its_surveys_and_tenants` /
> `test_deleting_a_user_nulls_out_created_by_and_surveyed_by`）を追加した。
> **見送った項目**: `test_raw_sql_and_migration_declare_the_same_columns` は列名の集合
> しか見ていない。型・NULL 可否・デフォルト値・インデックス定義の drift は検出しない
> （型マッピング層は壊れやすい新しい依存になるため見送り。テストの docblock に明記）。

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingSchemaTest
```

Expected: FAIL — `Failed asserting that false is true`（テーブルが無い）

- [ ] **Step 3: migration を書く**

`database/migrations/2026_08_12_000001_create_area_building_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 周辺ビル調査（テナント管理）の 3 テーブル。
     *
     * ⚠ 本番 DB は database/sql/2026-08-12-create-area-building-tables.sql を直接流して作る。
     *   この migration は SQLite テスト用のミラーで、片方だけ直すとテストだけが落ちる
     *   drift になる。列を足すときは必ず両方を同時に直すこと。
     */
    public function up(): void
    {
        Schema::create('area_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('ビル名');
            $table->string('address')->nullable()->comment('所在地');
            $table->decimal('latitude', 10, 7)->nullable()->comment('緯度');
            $table->decimal('longitude', 10, 7)->nullable()->comment('経度');
            $table->integer('total_floors')->nullable()->comment('総階数');
            $table->text('notes')->nullable()->comment('備考');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('登録者');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name', 'idx_area_buildings_name');
        });

        Schema::create('area_building_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_building_id')->constrained('area_buildings')->cascadeOnDelete();
            $table->date('surveyed_month')->comment('調査年月。日は 01 固定');
            $table->unsignedInteger('operating_count')->default(0)->comment('営業');
            $table->unsignedInteger('vacant_count')->default(0)->comment('空き');
            $table->unsignedInteger('unknown_count')->default(0)->comment('不明');
            $table->foreignId('surveyed_by')->nullable()->constrained('users')->nullOnDelete()->comment('調査者');
            $table->text('notes')->nullable()->comment('その回の所見');
            $table->timestamps();

            $table->unique(['area_building_id', 'surveyed_month'], 'uk_area_survey_building_month');
        });

        Schema::create('area_building_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_building_id')->constrained('area_buildings')->cascadeOnDelete();
            $table->integer('floor')->nullable()->comment('階。地下は負数（B1 = -1）');
            $table->string('room_number', 50)->nullable()->comment('部屋番号・区画名');
            $table->string('name')->nullable()->comment('テナント名。空き区画の行では NULL');
            $table->string('industry', 100)->nullable()->comment('業種');
            $table->string('status', 20)->comment('operating / vacant / unknown');
            $table->date('confirmed_on')->nullable()->comment('最終確認日');
            $table->date('moved_out_on')->nullable()->comment('退去日');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['area_building_id', 'moved_out_on'], 'idx_area_tenants_building_active');
            $table->index('name', 'idx_area_tenants_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_building_tenants');
        Schema::dropIfExists('area_building_surveys');
        Schema::dropIfExists('area_buildings');
    }
};
```

- [ ] **Step 4: raw SQL を書く**

`database/sql/2026-08-12-create-area-building-tables.sql`:

```sql
-- 周辺ビル調査（テナント管理）— 2026-08-12
--
-- ⚠ database/migrations/2026_08_12_000001_create_area_building_tables.php と対で維持すること。
--   片方だけ直すと SQLite テストだけが落ちる drift になる
--   （AreaBuildingSchemaTest::test_raw_sql_and_migration_declare_the_same_columns が拾う）。
--
-- 適用: sudo mysql manage < database/sql/2026-08-12-create-area-building-tables.sql
--   CREATE TABLE IF NOT EXISTS なので、途中で失敗しても再実行して安全。
--
-- 代替: php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/2026-08-12-create-area-building-tables.sql'));"
--   ⚠ このファイルは CREATE TABLE を 3 本含むため、PDO::exec() のマルチステートメント
--   挙動に依存する。この方式はこのリポジトリに前例が無い（database/sql/ の他ファイルで
--   tinker + DB::unprepared() を案内しているのは単一ステートメントのみ。複数テーブルの
--   ファイルは create_mansion_tables.sql 等すべて sudo mysql の直接実行を案内している）。

CREATE TABLE IF NOT EXISTS area_buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'ビル名',
    address VARCHAR(255) NULL COMMENT '所在地',
    latitude DECIMAL(10,7) NULL COMMENT '緯度',
    longitude DECIMAL(10,7) NULL COMMENT '経度',
    total_floors INT NULL COMMENT '総階数',
    notes TEXT NULL COMMENT '備考',
    created_by BIGINT UNSIGNED NULL COMMENT '登録者',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_area_buildings_name (name),
    CONSTRAINT fk_area_buildings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビル（恒久情報）';

CREATE TABLE IF NOT EXISTS area_building_surveys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_building_id BIGINT UNSIGNED NOT NULL,
    surveyed_month DATE NOT NULL COMMENT '調査年月。日は 01 固定',
    operating_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '営業',
    vacant_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '空き',
    unknown_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '不明',
    surveyed_by BIGINT UNSIGNED NULL COMMENT '調査者',
    notes TEXT NULL COMMENT 'その回の所見',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_area_survey_building_month (area_building_id, surveyed_month),
    CONSTRAINT fk_area_surveys_building FOREIGN KEY (area_building_id) REFERENCES area_buildings(id) ON DELETE CASCADE,
    CONSTRAINT fk_area_surveys_surveyed_by FOREIGN KEY (surveyed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビルの調査回（時点情報）';

CREATE TABLE IF NOT EXISTS area_building_tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_building_id BIGINT UNSIGNED NOT NULL,
    floor INT NULL COMMENT '階。地下は負数（B1 = -1）',
    room_number VARCHAR(50) NULL COMMENT '部屋番号・区画名',
    name VARCHAR(255) NULL COMMENT 'テナント名。空き区画の行では NULL',
    industry VARCHAR(100) NULL COMMENT '業種',
    status VARCHAR(20) NOT NULL COMMENT 'operating / vacant / unknown',
    confirmed_on DATE NULL COMMENT '最終確認日',
    moved_out_on DATE NULL COMMENT '退去日',
    notes TEXT NULL COMMENT '備考',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_area_tenants_building_active (area_building_id, moved_out_on),
    INDEX idx_area_tenants_name (name),
    CONSTRAINT fk_area_tenants_building FOREIGN KEY (area_building_id) REFERENCES area_buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビルの入居テナント（現況リスト）';
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingSchemaTest
```

Expected: PASS（6 tests。2026-08-14 コード品質レビューで CASCADE / SET NULL の回帰テスト 2 本を追加）

- [ ] **Step 6: 変異テストで drift 検出を実測する**

`test_raw_sql_and_migration_declare_the_same_columns`（双方向比較。2026-08-14 コード品質レビューで
`columnsInRawSql()` を行分割からトップレベルカンマ分割＋境界付きテーブル名検索へ書き直した最終版）:

| # | 変異 | 期待 |
|---|---|---|
| 1 | raw SQL から `industry VARCHAR(100) NULL ...` の行を丸ごと削除 | 赤 |
| 2 | migration から `$table->string('industry', 100)->nullable()...` の行を削除 | 赤 |
| 3 | migration にだけ `$table->string('dummy_col')->nullable();` を追加 | 赤 |
| 4 | raw SQL にだけ `dummy_col VARCHAR(50) NULL,` を追加 | 赤 |
| 5 | `columnsInRawSql()` が常に `[]` を返すように潰す | `assertNotEmpty` で赤（`assertSame([], [])` で緑にならないこと） |
| 6 | raw SQL の既存行に `extra_field VARCHAR(10) NULL COMMENT 'ダミー',` を**圧縮して**追記（1 行に複数カラム） | 新ロジックで**赤**。同じ変異のまま `columnsInRawSql()` を行分割の旧ロジックに戻すと**緑**になることを確認済み（旧ロジックはこの drift を原理的に検出できない） |
| 7 | `DECIMAL(10,7)` を持つ `latitude` の行・`COMMENT '...'` にカンマを含む行が誤分割されていないか | 通常状態・コメント内カンマ変異のどちらも green。`columnsInRawSql()` の戻り値を実測（`dump()` 相当）し `latitude` `longitude` が個別に、かつカンマで分裂せず拾えていることを確認済み |
| 8 | raw SQL の先頭に `area_buildings_history`（`ghost_column` を持つ）の架空 CREATE TABLE を挿入 | 境界付き正規表現により本物の `area_buildings`（11 列）を正しく拾い、`ghost_column` を誤抽出しないことを実測確認済み |

`test_same_building_and_month_cannot_be_inserted_twice`（UNIQUE 制約）:

| # | 変異 | 期待 |
|---|---|---|
| 9 | migration の `$table->unique([...], 'uk_area_survey_building_month');` を削除 | 赤 |

`test_deleting_a_building_cascades_to_its_surveys_and_tenants` / `test_deleting_a_user_nulls_out_created_by_and_surveyed_by`
（2026-08-14 コード品質レビューで追加した FK 回帰テスト）:

| # | 変異 | 期待 |
|---|---|---|
| 10 | `area_building_surveys.area_building_id` の `cascadeOnDelete()` を `restrictOnDelete()` に変更 | CASCADE のテストが赤（`FOREIGN KEY constraint failed`） |
| 11 | `area_buildings.created_by` の `nullOnDelete()` を外す | SET NULL のテストが赤（`FOREIGN KEY constraint failed`） |

- [ ] 上記すべてを当てて赤になることを確認する。変異のたびに `git diff` が非空であることを確認する
- [ ] 全部戻して PASS を確認し、`git status` が clean であることを確認する

⚠ **見送った項目**: raw SQL 適用コマンドの案内（`sudo mysql manage < file` を第一の方法にし、
tinker + `DB::unprepared()` は複数 `CREATE TABLE` のマルチステートメント実行に前例が無い旨の
注記付きで代替として残す）はコード品質レビュー Important #5 で修正済み。型・NULL 可否・
デフォルト値・インデックス定義の drift 検出（Important #3）は型マッピング層が新しい壊れやすい
依存になるため見送り、テストの docblock に検査範囲の限界を明記した。

- [ ] **Step 7: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の 3 テーブルを追加（raw SQL + migration）`

---

## Task 3: Enum `AreaTenantStatus`

**Files:**
- Create: `app/Enums/AreaTenantStatus.php`
- Test: `tests/Unit/Tenant/AreaTenantStatusTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/AreaTenantStatusTest.php`:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Enums\AreaTenantStatus;
use PHPUnit\Framework\TestCase;

class AreaTenantStatusTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('営業', AreaTenantStatus::Operating->label());
        $this->assertSame('空き', AreaTenantStatus::Vacant->label());
        $this->assertSame('不明', AreaTenantStatus::Unknown->label());
    }

    /** バッジは inline style を返す(Tailwind クラスは返さない — プロジェクト規約) */
    public function test_badge_style_is_inline_css_not_tailwind_classes(): void
    {
        foreach (AreaTenantStatus::cases() as $case) {
            $style = $case->badgeStyle();
            $this->assertStringContainsString('background:', $style);
            $this->assertStringContainsString('color:', $style);
            // 単純な 'bg-' 部分一致だけでは 'text-emerald-800' のような bg- を含まない
            // Tailwind クラスの混入を検出できない。代表的なユーティリティ接頭辞をまとめて検査する。
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:bg|text|border|rounded|px|py|font|shadow)-/',
                $style,
                'Tailwind クラスが混入している(バッジは inline style で返す規約)'
            );
        }
    }

    /** status カラムは VARCHAR(20)。将来ケースを足したときに収まりきらないことに気づけるようにする */
    public function test_values_fit_the_database_column(): void
    {
        foreach (AreaTenantStatus::cases() as $case) {
            $this->assertLessThanOrEqual(20, strlen($case->value), "{$case->name} の値が status VARCHAR(20) に収まらない");
        }
    }

    /**
     * Excel 取込の状態エイリアス(設計 §7.2)。
     * 空欄と「?」は不明。判定できない語も不明に倒す(勝手に営業扱いしない)。
     */
    public function test_from_raw_label_normalizes_aliases(): void
    {
        foreach (['営業中', '営業', '入居', '入居中', '稼働'] as $raw) {
            $this->assertSame(AreaTenantStatus::Operating, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
        foreach (['空室', '空き', '空き店舗', '空店舗'] as $raw) {
            $this->assertSame(AreaTenantStatus::Vacant, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
        foreach ([null, '', '  ', '?', '？', '不明', 'よくわからない'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), var_export($raw, true));
        }
    }

    /** 全角スペース(U+3000)混じりでも正規化できる */
    public function test_from_raw_label_ignores_full_width_space(): void
    {
        $this->assertSame(AreaTenantStatus::Operating, AreaTenantStatus::fromRawLabel("営　業"));
        $this->assertSame(AreaTenantStatus::Vacant, AreaTenantStatus::fromRawLabel("空　室"));
    }

    /**
     * 判定は順序に依存させない。否定語で営業判定を打ち消す、および
     * 営業系・空き系の両方の語を含む(判定不能)は Unknown に倒す。
     *
     * 単語単体しか見ないテストだと、順序で片方が勝ってしまう誤実装を検出できない
     * (コード品質レビューで発見: 「不稼働」「入居者退去済み」等が誤って Operating と判定されていた)。
     */
    public function test_from_raw_label_falls_back_to_unknown_for_ambiguous_or_negated_text(): void
    {
        // 否定語が「営業」判定を打ち消す(Vacant への昇格はしない — 空き系の語を含まないため)
        foreach (['不稼働（休業中）', '入居者退去済み', '退去済', '募集中'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), $raw);
        }

        // 営業系・空き系の両方の信号を含む場合は判定不能 = Unknown(順序で勝敗を決めない)
        foreach (['空床あり、他は稼働', '空室だが近日営業予定', '空き営業中'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaTenantStatusTest
```

Expected: FAIL — `Class "App\Enums\AreaTenantStatus" not found`

- [ ] **Step 3: 実装を書く**

`app/Enums/AreaTenantStatus.php`:

```php
<?php

namespace App\Enums;

/**
 * 周辺ビルの入居テナントの状態。
 *
 * ⚠ モデルで casts() にかけるので、読み出した属性は既に enum インスタンス。
 *   キャスト済み属性に tryFrom() を呼ばないこと（Bug #22）。
 *   クエリで使うときだけ ->value を渡す。
 */
enum AreaTenantStatus: string
{
    case Operating = 'operating';
    case Vacant    = 'vacant';
    case Unknown   = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Operating => '営業',
            self::Vacant    => '空き',
            self::Unknown   => '不明',
        };
    }

    /** ステータスバッジは inline style を返す（Tailwind クラス指定は規約で NG） */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Operating => 'background: #d1fae5; color: #065f46;',
            self::Vacant    => 'background: #fee2e2; color: #991b1b;',
            self::Unknown   => 'background: #f3f4f6; color: #374151;',
        };
    }

    /** 「営業」と判定する語（現況が営業中であることを示す） */
    private const OPERATING = ['営業', '入居', '稼働'];

    /** 「空き」と判定する語（現況が空きであることを示す） */
    private const VACANT = ['空室', '空き', '空店舗', '空'];

    /**
     * これらを含むときは「営業」と判定しない（現況が営業でないことを示す語）。
     *
     * ⚠ この一覧は Vacant への昇格には使わない。判定を Unknown 側へ倒すだけに使う。
     *   空室率は「不明」も空きに数える（設計 §4）ので、Unknown へ倒すのは安全側。
     *   逆に Operating へ倒すと空室率が下振れして経営指標が狂う。
     */
    private const NOT_OPERATING = ['不', '非', '退去', '撤退', '閉店', '休業'];

    /**
     * Excel 取込の状態列を正規化する（設計 §7.2）。
     * 判定できない値は Unknown に倒す（勝手に営業扱いすると空室率が下振れするため）。
     *
     * ⚠ 営業系・空き系の両方の語を含む場合、あるいはどちらも含まない場合は判定不能として
     *   Unknown に倒す（順序に依存させない — 「空き営業中」のような文言は現況が確定できない）。
     *
     * DAD の工事案件 Excel 取込はエイリアス解決をクライアント側 JS で行っているが、
     * こちらはビル名の DB 突合が要り最初から PHP 側処理が必要なため、Enum に置いている。
     *
     * ⚠ 既知の限界: VACANT の単一文字 needle 「空」は広く一致する（例:「空調工事中」も Vacant 判定）。
     *   空室率を過大に出す方向 = 安全側であり、「空」単体も実データにありうる値のため意図的に許容している。
     */
    public static function fromRawLabel(?string $raw): self
    {
        // ⚠ /u は PCRE2_UCP も立てるので \s だけでも U+3000 に当たる(PHP 8.3 / PCRE 10.47 で実測)。
        //   \x{3000} の明示は冗長だが、UCP 無効なビルドでも同じ挙動になるよう残している。
        $s = preg_replace('/[\s\x{3000}]+/u', '', (string) $raw);

        if ($s === '' || $s === '?' || $s === '？') {
            return self::Unknown;
        }

        $negated       = self::containsAny($s, self::NOT_OPERATING);
        $hitsOperating = ! $negated && self::containsAny($s, self::OPERATING);
        $hitsVacant    = self::containsAny($s, self::VACANT);

        // 両方の信号が立つときは判定不能。順序で勝敗を決めず Unknown へ倒す。
        if ($hitsOperating && $hitsVacant) {
            return self::Unknown;
        }

        if ($hitsOperating) {
            return self::Operating;
        }

        if ($hitsVacant) {
            return self::Vacant;
        }

        return self::Unknown;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
```

> **2026-08-14 追記（コード品質レビューによる訂正）:** 当初の `fromRawLabel()` は「営業系の語を先に走査 → ヒットすれば即 Operating」という**順序依存**の実装だった。実測で `不稼働（休業中）`（「不」＝否定なのに `稼働` にヒット）・`入居者退去済み`（退去済みなのに `入居` にヒット）・`空床あり、他は稼働`（両方の信号があるのに順序で Operating が勝つ）が誤って Operating と判定されることが判明した。docblock に書いた「判定できない値は Unknown に倒す（安全側）」という原則そのものに反する誤爆だった。**順序に依存しない構造**（否定語で営業判定を打ち消す `NOT_OPERATING`、両方の信号が立つ場合は判定不能として Unknown、に再設計）に置き換えた。語彙の拡張はしていない（すべての変化は Unknown 側へ向かう安全な訂正のみ）。

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaTenantStatusTest
```

Expected: PASS（6 tests）

- [ ] **Step 5: 変異テストで 3 通り確認する**

- [ ] `return self::Unknown;`（末尾）を `return self::Operating;` に変える → `test_from_raw_label_normalizes_aliases` が **赤**
- [ ] `$s = preg_replace('/[\s\x{3000}]+/u', '', (string) $raw);` を `$s = (string) $raw;`（空白正規化を丸ごと外す）に変える → `test_from_raw_label_ignores_full_width_space` が **赤**
- [ ] 両方ヒット時の `if ($hitsOperating && $hitsVacant) { return self::Unknown; }` を削除して Operating 優先に戻す → `test_from_raw_label_falls_back_to_unknown_for_ambiguous_or_negated_text` が **赤**
- [ ] 戻して PASS を確認

> **2026-08-14 追記（実測による訂正）:** 当初の変異 2 は「`preg_replace('/[\s\x{3000}]+/u', ...)` を `preg_replace('/\s+/u', ...)` に変える」だったが、実測（PHP 8.3.30 / PCRE 10.47。ローカル CLI・本番とも同じ PHP 8.3 系）でこの変異は**挙動が変わらず**、そもそも変異になっていなかった。原因は PHP の `/u` 修飾子が `PCRE2_UTF` だけでなく `PCRE2_UCP` も立てるため、`\s` 単体でも U+3000 に一致すること（`\d` `\w` も同様に Unicode プロパティベースになる）。「PCRE の `\s` は `/u` を付けても U+3000 に当たらない」という前提そのものが誤りだった。実装（`[\s\x{3000}]+` の明示指定）は UCP 無効なビルドへの保険として妥当なため変更せず、コメントと本 Step の変異 2 だけを「空白正規化を丸ごと外す」に訂正した。

- [ ] **Step 6: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の入居状態 Enum を追加`

---

## Task 4: モデル 3 本

**Files:**
- Create: `app/Models/AreaBuilding.php`
- Create: `app/Models/AreaBuildingSurvey.php`
- Create: `app/Models/AreaBuildingTenant.php`
- Test: `tests/Feature/Tenant/AreaBuildingModelTest.php`

`RefreshDatabase` が要るので Unit ではなく Feature 側に置く（既存の `tests/Unit/Tenant/*` は DB 非依存のものだけ）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingModelTest.php`:

⚠ **実装時の同期（2026-08-16、コード品質レビュー Important #2 / Minor #3・#4 反映後）——
下のコードブロックは初回実装時点のものではなく、レビュー対応後の最終形。** 差分の要点:
`use App\Models\User;` 追加 / `test_normalize_name_collapses_tabs_and_newlines`・
`test_google_maps_url_requires_both_coordinates`・
`test_whitespace_only_address_is_normalized_to_null`・
`test_pending_geocode_excludes_whitespace_only_address`・
`test_soft_deleted_relations_resolve_via_with_trashed` を追加 /
`test_pending_geocode_skips_rows_that_already_have_coordinates` は
`$buildingA` を捕まえて ID ベースで上限を検証する形に変更（理由は本節末尾の
「直さないもの」の下、Step 8 の変異表の直前を参照）。

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaBuildingModelTest extends TestCase
{
    use RefreshDatabase;

    private function building(array $attributes = []): AreaBuilding
    {
        return AreaBuilding::create(array_merge(['name' => 'ミツワビル'], $attributes));
    }

    /**
     * surveyed_month は DATE 型だが意味は年月。日を 01 に正規化しないと
     * UNIQUE(area_building_id, surveyed_month) が同じ月の重複を止められない。
     */
    public function test_surveyed_month_is_normalized_to_the_first_of_the_month(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-17',
            'operating_count'  => 1,
        ]);

        $this->assertSame('2026-08-01', $survey->fresh()->surveyed_month->format('Y-m-d'));
    }

    /** 更新時も正規化される（saving フックなので create/update の両方を通る） */
    public function test_surveyed_month_is_normalized_on_update_too(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1,
        ]);

        // ⚠ fresh() で取り直す。create() したままのインスタンスは wasRecentlyCreated が
        //    true のままで、実運用（ルートモデルバインディング）の経路と違う（Bug #39）。
        $survey = $survey->fresh();
        $this->assertFalse($survey->wasRecentlyCreated);

        $survey->update(['surveyed_month' => '2026-09-30']);

        $this->assertSame('2026-09-01', $survey->fresh()->surveyed_month->format('Y-m-d'));
    }

    /** 正規化があるので「同じ月の別の日」でも UNIQUE に弾かれる */
    public function test_same_month_with_a_different_day_collides(): void
    {
        $building = $this->building();
        AreaBuildingSurvey::create([
            'area_building_id' => $building->id, 'surveyed_month' => '2026-08-01', 'operating_count' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AreaBuildingSurvey::create([
            'area_building_id' => $building->id, 'surveyed_month' => '2026-08-25', 'operating_count' => 2,
        ]);
    }

    /** 空室率はモデルからも VacancyRate と同じ値で引ける */
    public function test_survey_exposes_vacancy_rate(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 2, 'unknown_count' => 0,
        ]);

        $this->assertSame(3, $survey->totalUnits());
        $this->assertSame(66.6, $survey->vacancyRate());
        $this->assertSame('66.6%', $survey->vacancyRateLabel());
        $this->assertSame('2026年8月', $survey->monthLabel());
    }

    public function test_survey_without_units_has_no_rate(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id, 'surveyed_month' => '2026-08-01',
        ]);

        $this->assertNull($survey->vacancyRate());
        $this->assertSame('—', $survey->vacancyRateLabel());
    }

    /** 突合キー: 前後の空白を落とし、全角空白は半角に、連続空白は 1 個に潰す */
    public function test_normalize_name(): void
    {
        $this->assertSame('ミツワビル', AreaBuilding::normalizeName('  ミツワビル '));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ　ビル"));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName('ミツワ   ビル'));
        $this->assertSame('', AreaBuilding::normalizeName(null));

        // ⚠ 内部の空白は残す（「ミツワ ビル」と「ミツワビル」は別のビルとして扱う）。
        //    全部潰すと別のビルの調査回を誤って同じビルに付けてしまう。
        $this->assertNotSame(
            AreaBuilding::normalizeName('ミツワ ビル'),
            AreaBuilding::normalizeName('ミツワビル')
        );
    }

    /** タブ・改行も半角スペース 1 個に正規化する（\s は /u の有無に関わらず ASCII 空白を含む） */
    public function test_normalize_name_collapses_tabs_and_newlines(): void
    {
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ\tビル"));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ\nビル"));
    }

    public function test_google_maps_url_only_when_coordinates_exist(): void
    {
        $this->assertNull($this->building()->googleMapsUrl());

        $withCoords = $this->building(['name' => '座標あり', 'latitude' => 33.8392, 'longitude' => 132.7657]);
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=33.8392000,132.7657000',
            $withCoords->googleMapsUrl()
        );
    }

    /** 緯度・経度のどちらか片方だけでは URL を出さない（hasCoordinates() の && 短絡が肝） */
    public function test_google_maps_url_requires_both_coordinates(): void
    {
        $latOnly = $this->building(['name' => '緯度のみ', 'latitude' => 33.8392]);
        $lonOnly = $this->building(['name' => '経度のみ', 'longitude' => 132.7657]);

        $this->assertNull($latOnly->googleMapsUrl());
        $this->assertNull($lonOnly->googleMapsUrl());
    }

    /**
     * 座標一括取得の対象は「latitude が NULL」かつ「住所がある」行だけ。
     * ⚠ 二重課金の防止が load-bearing（設計 §7.4 / §11-11）。
     */
    public function test_pending_geocode_skips_rows_that_already_have_coordinates(): void
    {
        $buildingA = $this->building(['name' => '未取得A', 'address' => '松山市1-1']);
        $this->building(['name' => '取得済み', 'address' => '松山市2-2', 'latitude' => 33.8, 'longitude' => 132.7]);
        $this->building(['name' => '住所なし']);
        $this->building(['name' => '住所空文字', 'address' => '']);
        $this->building(['name' => '未取得B', 'address' => '松山市3-3']);

        $this->assertSame(2, AreaBuilding::pendingGeocodeCount());

        // 内容(対象が確かにこの2件だけ)を見る。並び順は問わないので比較前に揃える。
        $names = AreaBuilding::pendingGeocode(200)->pluck('name')->all();
        sort($names);
        $this->assertSame(['未取得A', '未取得B'], $names);

        // 上限が効いていることは「件数」と「どの行が返るか」で見る。
        // ⚠ 並び順そのものは SQLite が挿入順を素で返すため assertSame では固定できない
        //   （orderBy('id') を外しても緑のままになる。実測済み）。
        //   上限件数が意味を持つのは「ID の小さい順から取る」ことなので、そこを直接見る。
        $first = AreaBuilding::pendingGeocode(1);
        $this->assertCount(1, $first, '上限が効いていない');
        $this->assertSame($buildingA->id, $first->first()->id, 'ID の小さい順に取れていない');
    }

    /**
     * 空白だけの住所は保存時に null へ正規化される（読み取り側でなく書き込み側。Bug #38）。
     * 半角・全角スペース・タブ・改行のいずれも対象。
     */
    public function test_whitespace_only_address_is_normalized_to_null(): void
    {
        foreach (['  ', '　', "\t", "\n"] as $whitespace) {
            $building = $this->building(['name' => '空白住所', 'address' => $whitespace]);
            $this->assertNull($building->fresh()->address, var_export($whitespace, true));
        }
    }

    /**
     * 空白だけの住所のビルは座標一括取得の対象に入らない。
     * ⚠ 全角スペースは MySQL の PAD SPACE 照合でも '' と等しくならないため、
     *   クエリ側の <> '' だけでは本番でも取りこぼす（実測）。書き込み側の正規化で防ぐ。
     */
    public function test_pending_geocode_excludes_whitespace_only_address(): void
    {
        $this->building(['name' => '全角空白住所', 'address' => '　']);
        $this->building(['name' => '半角空白住所', 'address' => '  ']);

        $this->assertSame(0, AreaBuilding::pendingGeocodeCount());
        $this->assertCount(0, AreaBuilding::pendingGeocode(200));
    }

    public function test_tenant_casts_and_floor_label(): void
    {
        $tenant = AreaBuildingTenant::create([
            'area_building_id' => $this->building()->id,
            'floor'            => -1,
            'status'           => AreaTenantStatus::Vacant->value,
        ]);

        $tenant = $tenant->fresh();
        $this->assertInstanceOf(AreaTenantStatus::class, $tenant->status);
        $this->assertSame('B1F', $tenant->floorLabel());
        $this->assertTrue($tenant->isActive());

        $tenant->update(['floor' => 3, 'moved_out_on' => '2026-08-01']);
        $this->assertSame('3F', $tenant->fresh()->floorLabel());
        $this->assertFalse($tenant->fresh()->isActive());
    }

    /** 現況リストは moved_out_on IS NULL の行だけ */
    public function test_active_tenants_excludes_moved_out_rows(): void
    {
        $building = $this->building();
        AreaBuildingTenant::create(['area_building_id' => $building->id, 'name' => '在', 'status' => 'operating']);
        AreaBuildingTenant::create(['area_building_id' => $building->id, 'name' => '退', 'status' => 'operating', 'moved_out_on' => '2026-07-31']);

        $this->assertSame(['在'], $building->activeTenants()->pluck('name')->all());
        $this->assertSame(2, $building->tenants()->count());
    }

    /**
     * ソフトデリートしたビル・ユーザーでも withTrashed() 経由でリレーションが解決できる。
     * このリポジトリは withTrashed() の付け忘れで何度も本番不具合を出している（Bug #12 系）。
     * 対象 4 箇所: AreaBuilding::creator() / AreaBuildingSurvey::building() /
     * AreaBuildingSurvey::surveyor() / AreaBuildingTenant::building()。
     */
    public function test_soft_deleted_relations_resolve_via_with_trashed(): void
    {
        $creator = User::factory()->create();
        $building = $this->building(['created_by' => $creator->id]);
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $building->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1,
            'surveyed_by'      => $creator->id,
        ]);
        $tenant = AreaBuildingTenant::create([
            'area_building_id' => $building->id,
            'status'           => 'operating',
        ]);

        $creator->delete();  // User は SoftDeletes（退職者を想定）
        $building->delete(); // AreaBuilding は SoftDeletes

        // 調査回・テナントは物理削除されない行のまま、ソフトデリートされた親ビルを解決できる
        $this->assertSame($building->id, $survey->fresh()->building->id);
        $this->assertSame($building->id, $tenant->fresh()->building->id);

        // ソフトデリートしたユーザーも creator() / surveyor() で解決できる
        $trashedBuilding = AreaBuilding::withTrashed()->find($building->id);
        $this->assertSame($creator->id, $trashedBuilding->creator->id);
        $this->assertSame($creator->id, $survey->fresh()->surveyor->id);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingModelTest
```

Expected: FAIL — `Class "App\Models\AreaBuilding" not found`

- [ ] **Step 3: `AreaBuilding` を書く**

`app/Models/AreaBuilding.php`:

⚠ **実装時の同期（2026-08-16、コード品質レビュー Important #1・#2 反映後）——
下は最終形。** 差分の要点: `casts()` の直後に `booted()` の `saving` フックを追加
（空白だけの住所を `null` へ正規化。Important #1）／`latestSurvey()` に N+1 の docblock を追加
（Important #2）。理由と実測は Step 8 の変異表の直前を参照。

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 周辺ビル（恒久情報）。
 *
 * ⚠ 論理削除。調査回とテナントは FK ON DELETE CASCADE だが、SoftDeletes ではビル行が
 *   残るので子は消えない（復元可能にするための意図どおりの挙動。設計 §8）。
 */
class AreaBuilding extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'total_floors',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude'     => 'decimal:7',
            'longitude'    => 'decimal:7',
            'total_floors' => 'integer',
        ];
    }

    /**
     * ⚠ 空白だけの住所は null に寄せる。読み取り側（pendingGeocodeQuery）で弾くのではなく
     *   書き込み側で正規化する（読み取りで隠すと DB に嘘の値が残り続ける。Bug #38）。
     *   ⚠ 全角スペース（U+3000）は MySQL の PAD SPACE 照合でも '' と等しくならないため、
     *     クエリ側の <> '' では本番でも取りこぼす（実測）。
     */
    protected static function booted(): void
    {
        static::saving(function (AreaBuilding $building): void {
            if ($building->address !== null && self::normalizeName($building->address) === '') {
                $building->address = null;
            }
        });
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function surveys(): HasMany
    {
        return $this->hasMany(AreaBuildingSurvey::class, 'area_building_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(AreaBuildingTenant::class, 'area_building_id');
    }

    /** 現況の入居テナント（退去済みを除く） */
    public function activeTenants(): HasMany
    {
        return $this->tenants()->whereNull('moved_out_on');
    }

    /** ⚠ User は SoftDeletes（app/Models/User.php:16）。退職者が消えないよう withTrashed */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    // ============================================================
    // 表示ヘルパー
    // ============================================================

    /**
     * ⚠ N+1 に注意: 一覧で行ごとに呼ぶと 1+N クエリになる。一覧では使わず、
     *   相関サブクエリで最新調査回を引くこと（設計 §5.3 / Task 6 の AreaBuildingListService）。
     *   詳細画面など単発呼び出し専用。
     */
    public function latestSurvey(): ?AreaBuildingSurvey
    {
        return $this->surveys()->orderByDesc('surveyed_month')->orderByDesc('id')->first();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * 別タブで開く Google マップの URL。
     * ⚠ 詳細画面に埋め込み地図を置かず、このリンクで済ませる（課金ゼロ。設計 §6.0）。
     */
    public function googleMapsUrl(): ?string
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . $this->latitude . ',' . $this->longitude;
    }

    public function totalFloorsLabel(): string
    {
        return $this->total_floors === null ? '—' : $this->total_floors . '階';
    }

    // ============================================================
    // Excel 取込 / 座標一括取得
    // ============================================================

    /**
     * Excel 取込のビル名突合キー。
     *
     * 前後の空白を落とし、全角空白（U+3000）は半角に、連続空白は 1 個に潰す。
     * ⚠ 内部の空白まで消さないこと。「ミツワ ビル」と「ミツワビル」を同一視すると、
     *   別のビルの調査回を誤って同じビルにぶら下げる。重複して登録されるほうがまだ直せる。
     */
    public static function normalizeName(?string $name): string
    {
        // ⚠ /u は PCRE2_UCP も立てるので、下の \s+ だけで U+3000 も半角空白に潰せる
        //   （PHP 8.3 / PCRE 10.47 で実測）。この str_replace は冗長だが、
        //   UCP 無効なビルドでも同じ挙動になるよう残している。
        $s = str_replace("\u{3000}", ' ', (string) $name);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * 座標未取得のビル（住所があるものだけ）。
     *
     * ⚠ latitude IS NULL に限定するのが二重課金の防止そのもの。何度実行しても
     *   未設定分しか Google に投げない（設計 §7.4）。住所が空の行は最初から対象外。
     *
     * @return Collection<int, static>
     */
    public static function pendingGeocode(int $limit): Collection
    {
        return static::pendingGeocodeQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'address']);
    }

    public static function pendingGeocodeCount(): int
    {
        return static::pendingGeocodeQuery()->count();
    }

    private static function pendingGeocodeQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
            ->whereNull('latitude')
            ->whereNotNull('address')
            ->where('address', '<>', '');
    }
}
```

- [ ] **Step 4: `AreaBuildingSurvey` を書く**

`app/Models/AreaBuildingSurvey.php`:

```php
<?php

namespace App\Models;

use App\Support\VacancyRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 周辺ビルの調査回（時点情報）。SoftDeletes は持たない（物理削除。設計 §3.2）。
 */
class AreaBuildingSurvey extends Model
{
    protected $fillable = [
        'area_building_id',
        'surveyed_month',
        'operating_count',
        'vacant_count',
        'unknown_count',
        'surveyed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'surveyed_month'  => 'date',
            'operating_count' => 'integer',
            'vacant_count'    => 'integer',
            'unknown_count'   => 'integer',
        ];
    }

    /**
     * ⚠ surveyed_month は DATE 型だが意味は「年月」。日を 01 に正規化しないと
     *   UNIQUE(area_building_id, surveyed_month) が同じ月の重複を止められなくなる。
     *   create / update の両方を通したいので saving フックに置く。
     */
    protected static function booted(): void
    {
        static::saving(function (AreaBuildingSurvey $survey): void {
            if ($survey->surveyed_month !== null) {
                $survey->surveyed_month = Carbon::parse($survey->surveyed_month)->startOfMonth();
            }
        });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(AreaBuilding::class, 'area_building_id')->withTrashed();
    }

    /** ⚠ User は SoftDeletes。付け忘れると退職者が調査した行の調査者欄が空になる */
    public function surveyor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surveyed_by')->withTrashed();
    }

    public function totalUnits(): int
    {
        return $this->operating_count + $this->vacant_count + $this->unknown_count;
    }

    /** ⚠ 計算式をここに書かない。VacancyRate の 1 箇所を通す（Bug #41） */
    public function vacancyRate(): ?float
    {
        return VacancyRate::percent($this->operating_count, $this->vacant_count, $this->unknown_count);
    }

    public function vacancyRateLabel(): string
    {
        return VacancyRate::label($this->operating_count, $this->vacant_count, $this->unknown_count);
    }

    public function monthLabel(): string
    {
        return $this->surveyed_month === null ? '—' : $this->surveyed_month->format('Y年n月');
    }

    /** <input type="month"> の value 用 */
    public function monthInputValue(): ?string
    {
        return $this->surveyed_month?->format('Y-m');
    }
}
```

⚠ **実装時の訂正（2026-08-16）— 上記コードには無いが `protected $attributes` の DEFAULT ミラーが
load-bearing で必要だった。** `test_survey_without_units_has_no_rate`（`operating_count` /
`vacant_count` / `unknown_count` を一切指定せず `create()` した直後に `fresh()` を挟まず
`vacancyRate()` を呼ぶテスト）が実測で `TypeError: App\Support\VacancyRate::percent(): Argument #1
($operating) must be of type int, null given` になった。原因: `area_building_surveys` の
DB 列は `->default(0)` だが、Eloquent の `create()` は **渡された属性だけ**で INSERT 文を組む。
DB は省略された列に `DEFAULT 0` を適用するが、それは DB 側の話でしかなく、`fresh()`/`refresh()`
で読み直すまで in-memory の `$this->operating_count` 等は `null` のまま。`vacancyRate()` は
これを `VacancyRate::percent(int $operating, int $vacant, int $unknown)` という非 nullable
`int` 引数へそのまま渡すため、`null` を渡すと（weak mode でも scalar 型の暗黙変換対象は
int/float/string/bool のみで `null` は対象外なので）即 `TypeError`。実装した `AreaBuildingSurvey`
には次を追加している（`$fillable` 直後）:

```php
protected $attributes = [
    'operating_count' => 0,
    'vacant_count'    => 0,
    'unknown_count'   => 0,
];
```

DB の DEFAULT をミラーする標準的な Eloquent パターンで、`fresh()` を挟むテスト・実際に
値を指定する呼び出しの挙動は変えない。

- [ ] **Step 5: `AreaBuildingTenant` を書く**

`app/Models/AreaBuildingTenant.php`:

```php
<?php

namespace App\Models;

use App\Enums\AreaTenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 周辺ビルの入居テナント（現況リスト）。
 * 退去は moved_out_on を入れて履歴として残す（行は消さない）。
 */
class AreaBuildingTenant extends Model
{
    protected $fillable = [
        'area_building_id',
        'floor',
        'room_number',
        'name',
        'industry',
        'status',
        'confirmed_on',
        'moved_out_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'       => AreaTenantStatus::class,
            'floor'        => 'integer',
            'confirmed_on' => 'date',
            'moved_out_on' => 'date',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(AreaBuilding::class, 'area_building_id')->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->moved_out_on === null;
    }

    /** 地下は負数で持つ（B1 = -1） */
    public function floorLabel(): string
    {
        if ($this->floor === null) {
            return '—';
        }

        return $this->floor < 0 ? 'B' . abs($this->floor) . 'F' : $this->floor . 'F';
    }
}
```

- [ ] **Step 6: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingModelTest
```

Expected: PASS（10 tests）

- [ ] **Step 7: `surveyed_month` が SQLite に何として入るかを実測して記録する**

後続タスクの重複判定（`whereDate` が要るか `=` で足りるか）の根拠になる。
`test_surveyed_month_is_normalized_to_the_first_of_the_month` に一時的に次の 1 行を足して出力を見る:

```php
dump(\Illuminate\Support\Facades\DB::table('area_building_surveys')->value('surveyed_month'));
```

`'2026-08-01'` か `'2026-08-01 00:00:00'` かを下に書き残し、確認したらその行は消す。
**どちらであっても実装は `whereDate` を使う**（本番 MySQL とテスト SQLite で割れない書き方だから）。

計測結果: `'2026-08-01 00:00:00'`（2026-08-16 実装時に実測。SQLite は `date` cast の Carbon
インスタンスを保存する際に時刻部分 `00:00:00` を含むフル datetime 文字列として格納する。
`=` での完全一致比較は本番 MySQL の `DATE` 型（時刻部分を持たない）とテスト SQLite で
異なる文字列表現になりうるため、後続タスクの重複判定は計画どおり `whereDate()` を使うこと）

- [ ] **Step 8: 変異テストで 4 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `AreaBuildingSurvey::booted()` の `saving` フックを丸ごと削除 | `..._normalized_to_the_first_of_the_month` / `..._on_update_too` / `..._different_day_collides` が赤 |
| 2 | `AreaBuilding::normalizeName()` の `preg_replace('/\s+/u', ' ', $s)` を `preg_replace('/\s+/u', '', $s)` に | `test_normalize_name` が赤 |
| 3 | `pendingGeocodeQuery()` の `whereNull('latitude')` を削除 | `..._skips_rows_that_already_have_coordinates` が赤 |
| 4 | `activeTenants()` の `whereNull('moved_out_on')` を削除 | `..._excludes_moved_out_rows` が赤 |

- [x] ~~変異 1 を当てたまま、Step 1 の `$survey = $survey->fresh();` を消すと `..._on_update_too` が**素通り**することも確認する（`fresh()` が load-bearing であることの証明。Bug #39）~~
      → **実測すると素通りせず赤のままだった。この主張は誤りだったので下の訂正ブロックを読むこと。**
      `fresh()` は「本番の経路（ルートモデルバインディングで DB から取り直す）に忠実」という理由で
      残すが、**この実装では変異の検出には寄与していない。**

⚠ **実装時の訂正（2026-08-16）— 上の項目は実測で成立しなかった。プランどおりに実装せず、
実測結果をそのまま記録する。** 変異 1（`saving` フック全削除）を当てたまま、
`$survey = $survey->fresh();` の行を消しても（`assertFalse($survey->wasRecentlyCreated);` を
一緒に消しても）`..._on_update_too` は**素通りせず、正しく赤のまま**だった
（`assertSame('2026-09-01', ...)` に対し実際の値 `'2026-09-30'` で失敗）。

原因: Bug #39 の原型（`ReProcurement` 等）は `booted()` 側に
`if (wasChanged([...]) || $model->wasRecentlyCreated) { ... }` という **OR 分岐**を持ち、
`wasRecentlyCreated` が真のままだと `wasChanged()` 側の変異が隠れる、という構造だった。
一方 `AreaBuildingSurvey::booted()` の `saving` フックは
`if ($survey->surveyed_month !== null) { ...startOfMonth()... }` のみで、
**`wasRecentlyCreated` を一切参照しない無条件の正規化**。加えてこのテストの最終行は
`$survey->update(...)` の**後**に `$survey->fresh()->surveyed_month->format(...)` と
改めて DB から読み直しており、この最終 `fresh()`（Step 1 のコード内にもう 1 箇所ある、
消す対象に含まれていない別の呼び出し）だけで DB の実値（正規化されていなければ
`2026-09-30` のまま）を正しく検出できる。よって `$survey = $survey->fresh();`（中間の
リセット）は Bug #39 一般の教訓に沿った良い習慣ではあるが、**このテスト・この実装において
load-bearing ではない**（無くても mutation 1 は検出される）。過去の Bug #39 のパターンを
機械的に踏襲した結果、成立しない主張をプランに書いてしまっていた。

- [ ] すべて戻して PASS を確認

- [x] **Step 8 追加分（2026-08-16、コード品質レビュー Critical 0 / Important 2 / Minor 7 の
      うち Important 2 件 + Minor 3 件を修正）— 変異 4 通りを実測**

修正内容: ①`AreaBuilding` に `booted()` の `saving` フックを追加し、空白だけの住所を保存時に
`null` へ正規化（読み取り側の `pendingGeocodeQuery()` では弾かない。Bug #38 の教訓）
②`latestSurvey()` に N+1 の docblock を追加
③`test_pending_geocode_skips_rows_that_already_have_coordinates` の上限検証を、
並び順に依存した `assertSame` から「どの ID が返るか」を直接見る形へ変更
④`googleMapsUrl()` の片側座標ケース／`normalizeName()` のタブ・改行／
ソフトデリートしたビル・ユーザーに対する `withTrashed()` 4 箇所（`AreaBuilding::creator()` /
`AreaBuildingSurvey::building()` / `AreaBuildingSurvey::surveyor()` /
`AreaBuildingTenant::building()`）の回帰テストを追加。

| # | 変異 | 期待 | 実測 |
|---|---|---|---|
| 1 | `AreaBuilding::booted()` の `saving` フックを丸ごと削除 | `test_whitespace_only_address_is_normalized_to_null` / `..._excludes_whitespace_only_address` が赤 | 赤（想定どおり。他 13 本は影響なし） |
| 2 | `pendingGeocode()` の `orderBy('id')` を `orderByDesc('id')` に | `..._skips_rows_that_already_have_coordinates` が赤（`ID の小さい順に取れていない`） | 赤。⚠ 同テスト内の「内容」検証（`sort()` してから比較する行）はソート済みなので**通ったまま**——並び順の検出は新設した ID ベースの行だけが担っている |
| 3 | `hasCoordinates()` の `&&` を `\|\|` に | `test_google_maps_url_requires_both_coordinates` が赤 | 赤。実際の失敗値は `'https://www.google.com/maps/search/?api=1&query=33.8392000,'`（経度側が空のまま URL 化される）で、片側座標が漏れて外部に届く実害と一致 |
| 4 | `creator()` の `withTrashed()` を外す | `test_soft_deleted_relations_resolve_via_with_trashed` が赤（withTrashed 付け忘れを検出できることの証明） | エラー `Attempt to read property "id" on null`（`$trashedBuilding->creator->id` で発火）。付け忘れが起きても静かに null を返すだけでなく、テストとしてはっきり落ちることを確認 |

4 通りとも `git diff` が非空であること（変異が実際に当たっていること）を確認済み。
すべて戻して `vendor/bin/phpunit --filter AreaBuildingModelTest`（15 tests, 42 assertions）
および全体 `vendor/bin/phpunit`（552 tests, 2982 assertions）が green であることを確認済み。

### 直さないもの（レビュー Minor #5〜#7。判断の記録）

- **Minor #6「`AreaBuilding` が 3 つの関心事を持つ」**: 変えない。既存の先例
  （`Unit::generateDisplayName()` / `User::assignableWith()`）と同じ形で逸脱ではないが、
  同じ diff で `vacancyRate()` の計算式だけ `VacancyRate` へ切り出し、`normalizeName` /
  `pendingGeocode*` はモデルに残したという非対称がある。**Task 11（Excel 取込）でさらに
  ロジックが積まれるようなら切り出しを再検討する。**
- **Minor #7（FQCN 直書き `\Illuminate\Database\Eloquent\Builder`）**: 直さない。好みの範囲で
  必須ではない。直すなら `use` 文に寄せる。
- **Minor #5（`QueryException` のクラスだけ見ている）**: 変えない。Task 2 が同じ流儀
  （raw SQL の制約違反はメッセージでなくクラスで判定）を確立済みで、それに合わせている。

- [ ] **Step 9: コミット**

`/commit` で `feat(tenant): 周辺ビル調査のモデル 3 本を追加`

---

## Task 5: 日本語バリデーション項目名

**Files:**
- Modify: `lang/ja/validation.php`

`JapaneseValidationMessagesTest::test_every_validated_field_has_a_japanese_attribute_label` は**グローバルの `attributes` だけ**を見る（`tests/Feature/JapaneseValidationMessagesTest.php:113`）。
→ 第3引数で上書きするキーも**グローバルに存在させる**必要がある。コントローラを書く前に済ませておく。

**既に存在するキー（実測 2026-08-13）:** `name`(名称) / `address`(住所) / `notes`(備考) / `latitude`(緯度) / `longitude`(経度) / `floor`(階数) / `room_number`(号室) / `total_floors`(総階数) / `rows`(原価明細)
**追加が要るキー:** `industry` / `surveyed_month` / `surveyed_by` / `operating_count` / `vacant_count` / `unknown_count` / `confirmed_on` / `moved_out_on` / `survey_notes` / `kind` / `coordinates`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/JapaneseValidationMessagesTest.php` の末尾（クラスの閉じ括弧の直前）に追加:

```php
    /**
     * 周辺ビル調査で追加した項目名がグローバルに載っていること。
     *
     * ⚠ 第3引数で上書きするキー（name / address / room_number / floor / notes / rows）も
     *   グローバルに存在していないと test_every_validated_field_has_a_japanese_attribute_label が
     *   落ちる。上書きは「語を変える」だけで「登録する」ものではない。
     */
    public function test_area_building_survey_attributes_are_registered(): void
    {
        $attributes = Lang::get('validation.attributes');

        $expected = [
            'industry'        => '業種',
            'surveyed_month'  => '調査年月',
            'surveyed_by'     => '調査者',
            'operating_count' => '営業',
            'vacant_count'    => '空き',
            'unknown_count'   => '不明',
            'confirmed_on'    => '最終確認日',
            'moved_out_on'    => '退去日',
            'survey_notes'    => '所見',
            'kind'            => '取込種別',
            'coordinates'     => '取得した座標',
        ];

        foreach ($expected as $key => $label) {
            $this->assertArrayHasKey($key, $attributes, "attributes に {$key} が無い");
            $this->assertSame($label, $attributes[$key], "{$key} の和名が想定と違う");
        }
    }

    /**
     * 括弧の注記は項目名に含めない方針（Bug #37）。
     * 「営業（そのビルのテナント部屋数）」ではなく「営業」。
     */
    public function test_area_building_attributes_have_no_parenthetical_notes(): void
    {
        $attributes = Lang::get('validation.attributes');

        foreach (['operating_count', 'vacant_count', 'unknown_count', 'surveyed_month'] as $key) {
            $this->assertStringNotContainsString('（', $attributes[$key], "{$key} に括弧の注記が入っている");
        }
    }
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter 'JapaneseValidationMessagesTest::test_area_building'
```

Expected: FAIL — `attributes に industry が無い`

- [ ] **Step 3: `lang/ja/validation.php` にセクションを追加する**

`// --- 保証人・緊急連絡先（画面ラベルは「氏名」等だけなので接頭辞を付ける）---` の**直前**に挿入する:

```php
        // --- 周辺ビル調査（テナント管理）---
        // ⚠ name / address / room_number / floor / notes / rows は既存のグローバル値を変えず、
        //   各コントローラの validate() 第3引数で上書きする（第2引数は messages）。
        //     AreaBuildingController       … name→ビル名 / address→所在地
        //     AreaBuildingTenantController … name→テナント名 / room_number→部屋番号 / floor→階
        //     AreaBuildingSurveyController … notes→所見
        //     AreaBuildingImportController … rows→取込データ
        'industry' => '業種',
        'surveyed_month' => '調査年月',
        'surveyed_by' => '調査者',
        'operating_count' => '営業',
        'vacant_count' => '空き',
        'unknown_count' => '不明',
        'confirmed_on' => '最終確認日',
        'moved_out_on' => '退去日',
        'survey_notes' => '所見',                       // ビル登録画面で同時入力する初回調査の所見
        'kind' => '取込種別',
        'coordinates' => '取得した座標',

```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter JapaneseValidationMessagesTest
```

Expected: PASS（既存 8 本 + 追加 2 本 = 10 tests）

⚠ 2026-08-16 訂正: 当初「既存 9 本」と書いていたが実測は **8 本**だった
（`attributes` に足すキーの数 9 と、テストメソッドの本数を取り違えた記載）。

- [ ] **Step 5: 変異テストで確認する**

- [ ] `'industry' => '業種',` の行を削除 → `test_area_building_survey_attributes_are_registered` が **赤**
- [ ] `'operating_count' => '営業',` を `'operating_count' => '営業（テナント部屋数）',` に → `test_area_building_attributes_have_no_parenthetical_notes` が **赤**
- [ ] 戻して PASS を確認

- [ ] **Step 6: コミット**

`/commit` で `feat(tenant): 周辺ビル調査のバリデーション項目名を追加`

---

## Task 6: 一覧（サービス / コントローラ / ビュー / ルート / サイドバー）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingTestCase.php`
- Create: `tests/Feature/Tenant/AreaBuildingListTest.php`
- Create: `app/Services/Tenant/AreaBuildingListService.php`
- Create: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Create: `resources/views/tenant/area-buildings/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/partials/sidebar.blade.php`

⚠ **このタスクでは一覧ルート 1 本だけを定義する。** 詳細・登録・取込・座標取得のボタンは、
それぞれのルートを追加する Task 7 / 8 / 11 / 12 で一覧ビューに足す。
先にボタンだけ置くと `route()` が `RouteNotFoundException` を投げて一覧全体が 500 になる（Bug #25 と同型）。

- [ ] **Step 1: テスト共通の土台を書く**

`tests/Feature/Tenant/AreaBuildingTestCase.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Tests\TestCase;

/**
 * 周辺ビル調査の Feature テスト共通土台。
 *
 * ⚠ ファイル名が *Test.php ではないので PHPUnit のテスト探索には引っかからない（意図どおり）。
 */
abstract class AreaBuildingTestCase extends TestCase
{
    private bool $departmentsSeeded = false;

    /**
     * tenant 部門所属のユーザー。department.access:tenant を通過させ、
     * 403 が role ゲート由来であることを保証する。
     */
    protected function actor(UserRole $role): User
    {
        // ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }

        $user = User::factory()->create([
            'role'                 => $role->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'tenant')->value('id'));

        return $user;
    }

    protected function executive(): User
    {
        return $this->actor(UserRole::Executive);
    }

    protected function manager(): User
    {
        return $this->actor(UserRole::Manager);
    }

    protected function staff(): User
    {
        return $this->actor(UserRole::Staff);
    }

    protected function makeBuilding(string $name, array $attributes = []): AreaBuilding
    {
        return AreaBuilding::create(array_merge(['name' => $name], $attributes));
    }

    protected function makeSurvey(
        AreaBuilding $building,
        string $month,
        int $operating,
        int $vacant,
        int $unknown = 0,
        array $extra = []
    ): AreaBuildingSurvey {
        return AreaBuildingSurvey::create(array_merge([
            'area_building_id' => $building->id,
            'surveyed_month'   => $month,
            'operating_count'  => $operating,
            'vacant_count'     => $vacant,
            'unknown_count'    => $unknown,
        ], $extra));
    }

    protected function makeTenant(AreaBuilding $building, array $attributes = []): AreaBuildingTenant
    {
        return AreaBuildingTenant::create(array_merge([
            'area_building_id' => $building->id,
            'status'           => 'operating',
        ], $attributes));
    }

    /** ページャに載った行のビル名（表示順のまま） */
    protected function listedNames(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->viewData('rows')->items())
            ->map(fn (array $row) => $row['building']->name)
            ->all();
    }
}
```

- [ ] **Step 2: 一覧の失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingListTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingListTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** 閲覧は全ロール（設計 §8） */
    public function test_staff_can_view_the_list(): void
    {
        $this->actingAs($this->staff())->get('/tenant/area-buildings')->assertOk();
    }

    /** データが 1 件も無くても落ちない（Bug #27 型） */
    public function test_empty_data_renders(): void
    {
        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $response->assertSee('周辺ビル調査');
        $this->assertSame([], $this->listedNames($response));
    }

    /** 調査 0 件のビルも一覧に出る（率は「—」） */
    public function test_building_without_any_survey_is_listed(): void
    {
        $this->makeBuilding('未調査ビル');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $this->assertSame(['未調査ビル'], $this->listedNames($response));
    }

    /**
     * 「空室率: 全て」= 空値。
     *
     * ⚠ 実 HTTP でしか再現しない。ConvertEmptyStringsToNull は HTTP ミドルウェアなので
     *   Request::create() では '' のまま届き、この欠陥を原理的に検出できない（Bug #31）。
     */
    public function test_empty_vacancy_filter_means_all_over_real_http(): void
    {
        $this->makeSurvey($this->makeBuilding('満室ビル'), '2026-08-01', 10, 0);
        $this->makeSurvey($this->makeBuilding('空きビル'), '2026-08-01', 5, 5);
        $this->makeBuilding('未調査ビル');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=');

        $response->assertOk();
        $this->assertCount(3, $this->listedNames($response), '「全て」なのに絞り込まれている');
    }

    public function test_vacancy_bands(): void
    {
        $this->makeSurvey($this->makeBuilding('満室'), '2026-08-01', 10, 0);
        $this->makeSurvey($this->makeBuilding('率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('率30'), '2026-08-01', 7, 3);
        $this->makeSurvey($this->makeBuilding('率50'), '2026-08-01', 5, 5);
        $this->makeBuilding('未調査');

        $staff = $this->staff();

        $this->assertSame(
            ['満室'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=full'))
        );
        $this->assertSame(
            ['率50', '率30', '率10'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=any'))
        );
        $this->assertSame(
            ['率50', '率30'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over20'))
        );
        $this->assertSame(
            ['率50'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over40'))
        );
    }

    /** 「不明」は空き扱いなので率のバンドに効く（VacancyRate を通っている証拠） */
    public function test_unknown_counts_toward_the_vacancy_band(): void
    {
        $this->makeSurvey($this->makeBuilding('不明だらけ'), '2026-08-01', 5, 0, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=over40');

        $this->assertSame(['不明だらけ'], $this->listedNames($response));
    }

    /** 調査年フィルタは「最終調査年月」の年で見る */
    public function test_year_filter_uses_the_latest_survey(): void
    {
        $old = $this->makeBuilding('2025年止まり');
        $this->makeSurvey($old, '2025-06-01', 5, 5);

        $updated = $this->makeBuilding('2026年に再調査');
        $this->makeSurvey($updated, '2025-06-01', 5, 5);
        $this->makeSurvey($updated, '2026-08-01', 5, 5);

        $staff = $this->staff();

        $this->assertSame(
            ['2026年に再調査'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?year=2026'))
        );
        $this->assertSame(
            ['2025年止まり'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?year=2025'))
        );
    }

    public function test_keyword_searches_name_address_and_current_tenants(): void
    {
        $this->makeBuilding('大街道ビル');
        $this->makeBuilding('別名ビル', ['address' => '愛媛県松山市大街道1-1']);

        $withTenant = $this->makeBuilding('テナント持ちビル');
        $this->makeTenant($withTenant, ['name' => '大街道珈琲']);

        $this->makeBuilding('無関係ビル');

        $names = $this->listedNames(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('大街道'))
        );

        sort($names);
        $this->assertSame(['大街道ビル', 'テナント持ちビル', '別名ビル'], $this->sortedJa($names));
    }

    /** 退去済みテナント名では拾わない（もう居ない会社でヒットさせない。設計 §5.3） */
    public function test_keyword_ignores_moved_out_tenants(): void
    {
        $building = $this->makeBuilding('退去済みだけのビル');
        $this->makeTenant($building, ['name' => '撤退カフェ', 'moved_out_on' => '2026-07-31']);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('撤退カフェ'));

        $this->assertSame([], $this->listedNames($response));
    }

    /** 既定の並び順は空室率降順・未調査は末尾（設計 §5.3） */
    public function test_default_order_is_vacancy_rate_desc_with_unsurveyed_last(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('え率0'), '2026-08-01', 8, 0);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $this->assertSame(['う率50', 'い率10', 'え率0', 'あ未調査'], $this->listedNames($response));
    }

    public function test_paginates_at_twenty_per_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding(sprintf('ビル%02d', $i));
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $this->assertCount(20, $this->listedNames($response));
        $this->assertSame(25, $response->viewData('rows')->total());
    }

    /**
     * ページ送りでフィルタが維持されること。
     *
     * ⚠ URL を自分で組み立ててはいけない（`?keyword=x&page=2` と書くと、リンクが壊れていても
     *   必ず緑になる）。1 ページ目を描画させて nextPageUrl() を実際に辿る（Bug #31）。
     *
     * ⚠ このテストが検出するのは「appends / withQueryString を丸ごと忘れた」場合まで。
     *   `?? ''` のマッピング（null キーが http_build_query に捨てられる件）は、本機能では
     *   どのフィルタも既定が「全て」なので原理的に区別できない（プラン §1-3）。
     */
    public function test_pagination_keeps_the_keyword_filter(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding(sprintf('松山ビル%02d', $i));
        }
        $this->makeBuilding('別エリアビル');

        $first = $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('松山'));
        $first->assertOk();
        $this->assertSame(25, $first->viewData('rows')->total());

        $nextUrl = $first->viewData('rows')->nextPageUrl();
        $this->assertNotNull($nextUrl, '2 ページ目のリンクが出ていない');
        $this->assertStringContainsString('keyword=', $nextUrl, 'ページ送りリンクから keyword が落ちている');

        $second = $this->actingAs($this->staff())->get($nextUrl);
        $second->assertOk();
        $this->assertSame(25, $second->viewData('rows')->total(), '2 ページ目でフィルタが飛んでいる');
        $this->assertCount(5, $this->listedNames($second));
    }

    /** サイドバーに導線がある（両方のブロックを直したことの確認は Step 8 の変異で行う） */
    public function test_sidebar_has_the_link(): void
    {
        $this->actingAs($this->staff())
            ->get('/tenant/area-buildings')
            ->assertSee('周辺ビル調査');
    }

    /** 日本語を含む配列を安定した順で比較するためのヘルパー */
    private function sortedJa(array $names): array
    {
        usort($names, 'strcmp');

        return $names;
    }
}
```

⚠ `test_keyword_searches_name_address_and_current_tenants` は 3 件がヒットすることだけを見る。
順序は空室率がすべて `null` なので名前順のタイブレークに落ちるが、日本語の照合順序は
DB に依存するため `sortedJa()` で正規化してから比較している。

- [ ] **Step 3: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListTest
```

Expected: FAIL — 404（ルート未定義）

- [ ] **Step 4: 一覧サービスを書く**

`app/Services/Tenant/AreaBuildingListService.php`:

```php
<?php

namespace App\Services\Tenant;

use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Support\VacancyRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 周辺ビル一覧のクエリ組み立て。
 *
 * 2 層に分かれている:
 *   SQL … 最新調査回の 4 列を相関サブクエリで引き、キーワード検索で絞る
 *   PHP … 空室率の算出・空室率フィルタ・調査年フィルタ・並び替え・ページ切り出し
 *
 * ⚠ 率を SQL 側で計算しないこと。VacancyRate と二重実装になり（Bug #41）、
 *   さらに MySQL の `/` は小数を返すのに SQLite の `/` は整数除算なので値が食い違う。
 *
 * ⚠ 絞り込み後の全件を一度メモリに載せる。本番の想定棟数は数十〜数百なので問題ないが、
 *   数千を超えるようなら SQL 側の並び替えへ移す（そのときは率の算出も 1 箇所に保つ工夫が要る）。
 */
class AreaBuildingListService
{
    public const VACANCY_FULL   = 'full';
    public const VACANCY_ANY    = 'any';
    public const VACANCY_OVER20 = 'over20';
    public const VACANCY_OVER40 = 'over40';

    /** フィルタバーの選択肢（「全て」は空値なのでここには入れない） */
    public const VACANCY_OPTIONS = [
        self::VACANCY_FULL   => '満室（0%）',
        self::VACANCY_ANY    => '空きあり（1%以上）',
        self::VACANCY_OVER20 => '空室率 20% 以上',
        self::VACANCY_OVER40 => '空室率 40% 以上',
    ];

    public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $rows = $this->rows($request);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                // ⚠ withQueryString() ではなくこの形にする。null 値のキーは
                //   http_build_query が丸ごと捨てるため（Bug #31）。
                'query' => array_map(fn ($v) => $v ?? '', $request->query()),
            ]
        );
    }

    /**
     * 絞り込み・並び替え済みの行。
     *
     * @return Collection<int, array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, rate_label: string}>
     */
    public function rows(Request $request): Collection
    {
        $vacancy = $request->input('vacancy');
        $year    = $request->input('year');

        return $this->baseQuery($request)
            ->get()
            ->map(fn (AreaBuilding $building) => $this->toRow($building))
            ->filter(fn (array $row) => $this->matchesYear($row, $year) && $this->matchesVacancy($row, $vacancy))
            ->sortByDesc(fn (array $row) => [
                $row['rate'] === null ? 0 : 1,   // 未調査を末尾へ
                $row['rate'] ?? 0.0,             // 空室率 降順
            ])
            ->values();
    }

    /** フィルタバーの「調査年」選択肢（降順） */
    public function surveyYears(): array
    {
        return AreaBuildingSurvey::query()
            ->orderByDesc('surveyed_month')
            ->pluck('surveyed_month')
            ->map(fn ($month) => (int) Carbon::parse($month)->format('Y'))
            ->unique()
            ->values()
            ->all();
    }

    private function baseQuery(Request $request): Builder
    {
        // 最新 1 件だけを引く相関サブクエリ。with('surveys') で全件ロードすると
        // 棟数 × 調査回数を毎回引くことになる（設計 §5.3 の N+1 対策）。
        $latest = fn (string $column) => AreaBuildingSurvey::query()
            ->select($column)
            ->whereColumn('area_building_surveys.area_building_id', 'area_buildings.id')
            ->orderByDesc('surveyed_month')
            ->orderByDesc('id')
            ->limit(1);

        $query = AreaBuilding::query()
            ->select('area_buildings.*')
            ->addSelect([
                'latest_month'     => $latest('surveyed_month'),
                'latest_operating' => $latest('operating_count'),
                'latest_vacant'    => $latest('vacant_count'),
                'latest_unknown'   => $latest('unknown_count'),
            ]);

        $keyword = $request->input('keyword');
        if (filled($keyword)) {
            $like = '%' . $keyword . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('area_buildings.name', 'like', $like)
                    ->orWhere('area_buildings.address', 'like', $like)
                    // 現況の行だけ。退去済みまで拾うと「もう居ない会社」でヒットする
                    ->orWhereHas('tenants', fn ($t) => $t->whereNull('moved_out_on')->where('name', 'like', $like));
            });
        }

        // 空室率が同じ行のタイブレーク（PHP の sort は 8.0 以降 stable なのでこの順が残る）
        return $query->orderBy('area_buildings.name')->orderBy('area_buildings.id');
    }

    /** @return array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, rate_label: string} */
    private function toRow(AreaBuilding $building): array
    {
        $hasSurvey = $building->latest_month !== null;

        $operating = $hasSurvey ? (int) $building->latest_operating : null;
        $vacant    = $hasSurvey ? (int) $building->latest_vacant : null;
        $unknown   = $hasSurvey ? (int) $building->latest_unknown : null;

        return [
            'building'   => $building,
            'month'      => $hasSurvey ? Carbon::parse($building->latest_month) : null,
            'operating'  => $operating,
            'vacant'     => $vacant,
            'unknown'    => $unknown,
            'rate'       => $hasSurvey ? VacancyRate::percent($operating, $vacant, $unknown) : null,
            'rate_label' => $hasSurvey ? VacancyRate::label($operating, $vacant, $unknown) : '—',
        ];
    }

    private function matchesVacancy(array $row, mixed $vacancy): bool
    {
        // ⚠ 型ガードより先に null を「全て」として返す。ConvertEmptyStringsToNull により
        //   ?vacancy= は実 HTTP では null で届く（Request::create() では '' のまま。Bug #31）。
        if ($vacancy === null || $vacancy === '') {
            return true;
        }

        if (! is_string($vacancy) || ! array_key_exists($vacancy, self::VACANCY_OPTIONS)) {
            return true;
        }

        $rate = $row['rate'];
        if ($rate === null) {
            return false;   // 未調査は率で絞ると対象外
        }

        return match ($vacancy) {
            self::VACANCY_FULL   => $rate <= 0.0,
            self::VACANCY_ANY    => $rate > 0.0,
            self::VACANCY_OVER20 => $rate >= 20.0,
            self::VACANCY_OVER40 => $rate >= 40.0,
        };
    }

    private function matchesYear(array $row, mixed $year): bool
    {
        if ($year === null || $year === '') {
            return true;
        }

        if (! is_numeric($year)) {
            return true;
        }

        return $row['month'] !== null && (int) $row['month']->format('Y') === (int) $year;
    }
}
```

- [ ] **Step 5: コントローラを書く**

`app/Http/Controllers/Tenant/AreaBuildingController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Http\Request;

/**
 * 周辺ビル調査（テナント管理）。
 *
 * 権限は routes/web.php 側のミドルウェアで担保する（設計 §8）:
 *   閲覧 = 全ロール / 登録・編集 = role:executive,manager / 削除 = role:executive
 */
class AreaBuildingController extends Controller
{
    public function index(Request $request, AreaBuildingListService $service)
    {
        return view('tenant.area-buildings.index', [
            'rows'           => $service->paginate($request),
            'surveyYears'    => $service->surveyYears(),
            'vacancyOptions' => AreaBuildingListService::VACANCY_OPTIONS,
        ]);
    }
}
```

- [ ] **Step 6: ルートを追加する**

`routes/web.php` の `Route::prefix('tenant')->middleware('department.access:tenant')->group(...)` の中、
**問合せ管理ブロックの最後**（`->name('tenant.inquiries.updateStatus');` の直後、グループを閉じる `});` の直前）に挿入:

```php

        /*
        |------------------------------------------------------------------
        | 周辺ビル調査（20ルート）
        |------------------------------------------------------------------
        |
        | ⚠ /area-buildings/create /import /geocode は /{building} より必ず先に宣言する。
        |   後に置くとルーターが create を ID として解釈し、モデルバインディングで 404 になる。
        */

        // 一覧（全ロール閲覧可）
        Route::get('/area-buildings', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'index'])
            ->name('tenant.area-buildings.index');
```

- [ ] **Step 7: 一覧ビューを書く**

`resources/views/tenant/area-buildings/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', '周辺ビル調査')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">周辺ビル調査</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">周辺ビル調査</h1>
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('tenant.area-buildings.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="ビル名・所在地・テナント名"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <select onchange="document.getElementById('filter-form').submit()" name="vacancy"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">空室率: 全て</option>
            {{-- ⚠ option は @foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            @foreach($vacancyOptions as $value => $label)
                <option value="{{ $value }}" {{ request('vacancy') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="year"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">調査年: 全て</option>
            @foreach($surveyYears as $year)
                <option value="{{ $year }}" {{ (string) request('year') === (string) $year ? 'selected' : '' }}>{{ $year }}年</option>
            @endforeach
        </select>
        <a href="{{ route('tenant.area-buildings.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:900px;">
                    <colgroup>
                        <col style="width:22%">
                        <col style="width:26%">
                        <col style="width:8%">
                        <col style="width:7%">
                        <col style="width:7%">
                        <col style="width:7%">
                        <col style="width:10%">
                        <col style="width:13%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ビル名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所在地</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">総階数</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">営業</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空き</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">不明</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室率</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">最終調査</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm font-semibold text-gray-900">
                                    {{ $row['building']->name }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700">
                                    {{ $row['building']->address ?: '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['building']->totalFloorsLabel() }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['operating'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['vacant'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['unknown'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center font-bold text-gray-900 whitespace-nowrap">
                                    {{ $row['rate_label'] }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['month'] ? $row['month']->format('Y年n月') : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-sm text-gray-400">
                                    周辺ビルのデータがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション（->links() は使わない。プロジェクト規約 / Bug #24） --}}
        @if($rows->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($rows->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $rows->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                    @if($page == $rows->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($rows->hasMorePages())
                    <a href="{{ $rows->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
```

- [ ] **Step 8: サイドバーに導線を追加する（2 箇所）**

`resources/views/layouts/partials/sidebar.blade.php` には**同じテナント管理ブロックが 2 つある**
（68 行目付近と 344 行目付近）。両方の「問合せ管理」の直後、`{{-- サブ見出し: 分析 --}}` の直前に挿入する:

```blade
            <x-sidebar-item :href="url('/tenant/area-buildings')" label="周辺ビル調査" :active="request()->is('tenant/area-buildings*')" />
```

置換の確認:

```bash
grep -c "周辺ビル調査" resources/views/layouts/partials/sidebar.blade.php
```

Expected: `2`（1 だと片方しか直っていない）

- [ ] **Step 9: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListTest
```

Expected: PASS（12 tests）

- [ ] **Step 10: 走査テストを含む全体が緑であることを確認する**

```bash
vendor/bin/phpunit
```

Expected: PASS。`MobileLayoutTest`（テーブルが `scroll-hint-inner` の中にあり `table-layout:fixed` に
`min-width` があること）と `AlpineXShowDisplayConflictTest` が新しいビューを自動で拾う。

- [ ] **Step 11: 変異テストで 5 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `matchesVacancy()` の先頭 `if ($vacancy === null \|\| $vacancy === '')` を `if ($vacancy === '')` に | `test_empty_vacancy_filter_means_all_over_real_http` が赤 |
| 2 | `paginate()` の `'query' => ...` の行を削除 | `test_pagination_keeps_the_keyword_filter` が赤 |
| 3 | `sortByDesc()` の第1要素 `$row['rate'] === null ? 0 : 1` を `0` 固定に | `test_default_order_is_vacancy_rate_desc_with_unsurveyed_last` が赤 |
| 4 | `orWhereHas('tenants', ...)` の `whereNull('moved_out_on')` を削除 | `test_keyword_ignores_moved_out_tenants` が赤 |
| 5 | サイドバー 2 箇所のうち**片方だけ**を消す | `test_sidebar_has_the_link` は **緑のまま**（片方が残るため）。→ `grep -c` が 2 であることを手で確認するのが唯一の防御だと理解しておく |

⚠ 変異 5 は「テストで守れない」ことの確認。無理に検出しようとしてサイドバーの構造テストを
足すより、Step 8 の `grep -c` を手順として残すほうが素直（走査テストの盲点。Bug #45）。

- [ ] **Step 12: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の一覧を追加`

---

## Task 7: 詳細画面（乖離警告 / マップリンク / 調査履歴 / テナント一覧）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingShowTest.php`
- Create: `resources/views/tenant/area-buildings/show.blade.php`
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`（ビル名をリンク化＋操作列を追加）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingShowTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingShowTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_the_detail(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertOk()
            ->assertSee('ミツワビル');
    }

    /** 調査 0 件・テナント 0 件でも落ちない（Bug #27 型） */
    public function test_detail_renders_with_no_surveys_and_no_tenants(): void
    {
        $building = $this->makeBuilding('からっぽビル');

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('latestSurvey'));
        $this->assertNull($response->viewData('divergence'));
    }

    /**
     * 詳細に埋め込み地図を置かない（設計 §6.0 の費用方針）。
     * ⚠ この方針が崩れたら赤になる。Maps を読むと 1 棟開くたびに Dynamic Maps が課金される。
     */
    public function test_detail_does_not_load_the_maps_javascript_api(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $html = $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html, '詳細に埋め込み地図が入り込んでいる');
        $this->assertStringNotContainsString('new google.maps.Map', $html);
    }

    public function test_detail_links_out_to_google_maps_when_coordinates_exist(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=33.8392000,132.7657000', false)
            ->assertSee('Google マップで開く');
    }

    public function test_detail_shows_a_prompt_when_coordinates_are_missing(): void
    {
        $building = $this->makeBuilding('座標なしビル');

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertSee('位置未登録')
            ->assertDontSee('Google マップで開く');
    }

    /** 調査履歴は年月の降順 */
    public function test_surveys_are_listed_newest_first(): void
    {
        $building = $this->makeBuilding('履歴ビル');
        $this->makeSurvey($building, '2025-06-01', 5, 5);
        $this->makeSurvey($building, '2026-08-01', 8, 2);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $months = collect($response->viewData('surveys'))->map(fn ($s) => $s->monthLabel())->all();
        $this->assertSame(['2026年8月', '2025年6月'], $months);
    }

    // ============================================================
    // 乖離警告（設計 §5.4 / Bug #46）— 3 通り
    // ============================================================

    /** ① 明細が食い違うとき出る */
    public function test_divergence_warning_appears_when_counts_disagree(): void
    {
        $building = $this->makeBuilding('食い違いビル');
        $this->makeSurvey($building, '2026-08-01', 3, 1, 0);          // 入力値: 営業3 / 空き1 / 不明0
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => 'B', 'status' => AreaTenantStatus::Operating->value]);
        // 明細は 営業2 / 空き0 / 不明0 なので食い違う

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $divergence = $response->viewData('divergence');
        $this->assertNotNull($divergence, '乖離しているのに警告が出ていない');
        $this->assertSame(['operating' => 3, 'vacant' => 1, 'unknown' => 0], $divergence['input']);
        $this->assertSame(['operating' => 2, 'vacant' => 0, 'unknown' => 0], $divergence['counted']);

        // ⚠ 件数だけを assertSee で見ない。同じ数字が調査履歴の行にも出るので false-pass する
        //   （Bug #43 / #40）。警告ブロック固有の文言で見る。
        $response->assertSee('調査時の実測とテナント明細が一致していません');
    }

    /** ② 一致するときは出ない */
    public function test_no_warning_when_counts_agree(): void
    {
        $building = $this->makeBuilding('一致ビル');
        $this->makeSurvey($building, '2026-08-01', 2, 1, 0);
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => 'B', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => null, 'status' => AreaTenantStatus::Vacant->value]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'));
        $response->assertDontSee('調査時の実測とテナント明細が一致していません');
    }

    /** ③ 明細 0 行のビルでは比較しない（明細を入れていないだけで警告が出ると意味がない） */
    public function test_no_warning_when_there_are_no_tenant_rows(): void
    {
        $building = $this->makeBuilding('明細未入力ビル');
        $this->makeSurvey($building, '2026-08-01', 9, 1, 0);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'));
        $response->assertDontSee('調査時の実測とテナント明細が一致していません');
    }

    /** 退去済みは明細集計に入れない */
    public function test_moved_out_tenants_are_excluded_from_the_counted_side(): void
    {
        $building = $this->makeBuilding('退去ありビル');
        $this->makeSurvey($building, '2026-08-01', 1, 0, 0);
        $this->makeTenant($building, ['name' => '現', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => '退', 'status' => AreaTenantStatus::Operating->value, 'moved_out_on' => '2026-07-31']);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'), '退去済みを数えてしまっている');
        $this->assertCount(1, $response->viewData('activeTenants'));
        $this->assertCount(1, $response->viewData('movedOutTenants'));
    }

    /**
     * 空室率などの下流の値は常に入力値を正とする（設計 §5.4）。
     * ⚠ 明細に寄せると、明細が途中までしか入っていないビルの数字が壊れる。
     */
    public function test_vacancy_rate_follows_the_input_values_not_the_breakdown(): void
    {
        $building = $this->makeBuilding('入力値が正ビル');
        $this->makeSurvey($building, '2026-08-01', 5, 5, 0);   // 入力値ベースなら 50.0%
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertSame(50.0, $response->viewData('latestSurvey')->vacancyRate());
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingShowTest
```

Expected: FAIL — 404

- [ ] **Step 3: コントローラに `show` を足す**

`app/Http/Controllers/Tenant/AreaBuildingController.php` の `index()` の後に追加し、
`use` に `App\Enums\AreaTenantStatus` と `App\Models\AreaBuilding` を足す:

```php
    public function show(AreaBuilding $building)
    {
        $surveys = $building->surveys()
            ->with('surveyor')
            ->orderByDesc('surveyed_month')
            ->orderByDesc('id')
            ->get();

        $latestSurvey = $surveys->first();

        $tenants = $building->tenants()
            ->orderByDesc('floor')
            ->orderBy('room_number')
            ->orderBy('id')
            ->get();

        $activeTenants   = $tenants->filter(fn ($t) => $t->isActive())->values();
        $movedOutTenants = $tenants->reject(fn ($t) => $t->isActive())->values();

        return view('tenant.area-buildings.show', [
            'building'        => $building,
            'surveys'         => $surveys,
            'latestSurvey'    => $latestSurvey,
            'activeTenants'   => $activeTenants,
            'movedOutTenants' => $movedOutTenants,
            'divergence'      => $this->divergence($latestSurvey, $activeTenants),
        ]);
    }

    /**
     * 「調査時の実測（入力値）」と「テナント明細からの集計」の乖離（設計 §5.4 / Bug #46）。
     *
     * ⚠ 内訳と合計を別ソースのまま並べると無音で食い違う。両方を出して差があるときだけ警告する。
     * ⚠ 明細 0 行のビルでは比較しない（明細を入れていないだけで警告が出ると意味がない）。
     * ⚠ 下流の空室率は常に入力値を正とする。明細に寄せると、明細が途中までしか
     *   入っていないビルの数字が壊れる。
     *
     * @return array{input: array<string, int>, counted: array<string, int>}|null
     */
    private function divergence(?AreaBuildingSurvey $latest, Collection $activeTenants): ?array
    {
        if ($latest === null || $activeTenants->isEmpty()) {
            return null;
        }

        $input = [
            'operating' => $latest->operating_count,
            'vacant'    => $latest->vacant_count,
            'unknown'   => $latest->unknown_count,
        ];

        // ⚠ status はキャスト済みなので enum インスタンス。tryFrom() を呼ばない（Bug #22）
        $counted = [
            'operating' => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Operating)->count(),
            'vacant'    => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Vacant)->count(),
            'unknown'   => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Unknown)->count(),
        ];

        return $input === $counted ? null : ['input' => $input, 'counted' => $counted];
    }
```

追加する `use`:

```php
use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use Illuminate\Support\Collection;
```

- [ ] **Step 4: ルートを足す**

Task 6 で入れた一覧ルートの**直後**に追加する（`create` / `import` / `geocode` は Task 8 / 11 / 13 で
**このルートより前に**挿入するので、ここではコメントで場所を確保しておく）:

```php
        // ⚠ /area-buildings/create /import /geocode はこの行より上に置くこと

        // 詳細（全ロール閲覧可）
        Route::get('/area-buildings/{building}', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'show'])
            ->name('tenant.area-buildings.show');
```

- [ ] **Step 5: 詳細ビューを書く**

`resources/views/tenant/area-buildings/show.blade.php`:

```blade
@extends('layouts.app')

@section('title', $building->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $building->name }}</span>
@endsection

@section('content')

    <a href="{{ route('tenant.area-buildings.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        周辺ビル調査に戻る
    </a>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg max-lg:text-base font-bold text-gray-900">{{ $building->name }}</h1>
    </div>

    {{-- ヘッダ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <div class="text-xs font-semibold text-gray-500 mb-1">所在地</div>
                <div class="text-sm text-gray-800">{{ $building->address ?: '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold text-gray-500 mb-1">総階数</div>
                <div class="text-sm text-gray-800">{{ $building->totalFloorsLabel() }}</div>
            </div>
        </div>

        @if($latestSurvey)
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="text-xs font-semibold text-gray-500 mb-2">最新調査（{{ $latestSurvey->monthLabel() }}）</div>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="text-sm text-gray-700">営業 <strong class="text-gray-900">{{ $latestSurvey->operating_count }}</strong></span>
                    <span class="text-sm text-gray-700">空き <strong class="text-gray-900">{{ $latestSurvey->vacant_count }}</strong></span>
                    <span class="text-sm text-gray-700">不明 <strong class="text-gray-900">{{ $latestSurvey->unknown_count }}</strong></span>
                    <span class="text-sm text-gray-700">空室率 <strong class="text-base text-gray-900">{{ $latestSurvey->vacancyRateLabel() }}</strong></span>
                </div>
            </div>
        @else
            <div class="mt-4 pt-4 border-t border-gray-200 text-sm text-gray-400">調査データがまだありません。</div>
        @endif
    </div>

    {{-- 乖離の警告（Bug #46） --}}
    @if($divergence)
        <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <div class="text-sm font-bold text-amber-800 mb-2">調査時の実測とテナント明細が一致していません</div>
            <p class="text-xs text-amber-900 mb-2">
                空室率などの数字は<strong>調査時の実測（入力値）</strong>を正として算出しています。
                テナント明細の入力が途中の可能性があります。
            </p>
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="border-collapse" style="min-width:360px;">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-bold text-amber-900"></th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">営業</th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">空き</th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">不明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-xs font-semibold text-amber-900 whitespace-nowrap">調査時の実測（入力値）</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['operating'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['vacant'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['unknown'] }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-xs font-semibold text-amber-900 whitespace-nowrap">テナント明細からの集計</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['operating'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['vacant'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['unknown'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- 位置（埋め込み地図は置かない。Google マップへのリンクのみ。設計 §6.0） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">位置</div>
        @if($building->googleMapsUrl())
            <a href="{{ $building->googleMapsUrl() }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Google マップで開く
            </a>
            <div class="mt-2 text-xs text-gray-500">緯度 {{ $building->latitude }} / 経度 {{ $building->longitude }}</div>
        @else
            <div class="text-sm text-gray-400">位置未登録です。編集画面の「マップで確認」から座標を登録できます。</div>
        @endif
    </div>

    {{-- 調査履歴 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
            <div class="text-sm font-bold text-gray-800">調査履歴</div>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:760px;">
                    <colgroup>
                        <col style="width:14%"><col style="width:9%"><col style="width:9%"><col style="width:9%">
                        <col style="width:12%"><col style="width:15%"><col style="width:32%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">調査年月</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">営業</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空き</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">不明</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室率</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">調査者</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所見</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($surveys as $survey)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-sm font-semibold text-gray-900 whitespace-nowrap">{{ $survey->monthLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->operating_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->vacant_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->unknown_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center font-bold text-gray-900">{{ $survey->vacancyRateLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $survey->surveyor?->name ?? '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $survey->notes ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-8 text-center text-sm text-gray-400">調査履歴がありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    </div>

    {{-- 入居テナント --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
            <div class="text-sm font-bold text-gray-800">入居テナント（現況 {{ $activeTenants->count() }} 件）</div>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:720px;">
                    <colgroup>
                        <col style="width:9%"><col style="width:13%"><col style="width:28%">
                        <col style="width:18%"><col style="width:10%"><col style="width:22%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">階</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部屋番号</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">テナント名</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">業種</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">最終確認日</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($activeTenants as $tenant)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">{{ $tenant->floorLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $tenant->room_number ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm font-semibold text-gray-900">{{ $tenant->name ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $tenant->industry ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge" style="{{ $tenant->status->badgeStyle() }}">{{ $tenant->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">{{ $tenant->confirmed_on?->format('Y/m/d') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">入居テナントの明細がありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        @if($movedOutTenants->isNotEmpty())
            <details class="mt-4">
                <summary class="text-xs font-semibold text-gray-500 cursor-pointer hover:text-gray-700">
                    退去済み {{ $movedOutTenants->count() }} 件を表示
                </summary>
                <ul class="mt-2 space-y-1">
                    @foreach($movedOutTenants as $tenant)
                        <li class="text-xs text-gray-500">
                            {{ $tenant->floorLabel() }} {{ $tenant->room_number }} {{ $tenant->name ?: '（名称なし）' }}
                            <span class="text-gray-400">— {{ $tenant->moved_out_on?->format('Y/m/d') }} 退去</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

@endsection
```

- [ ] **Step 6: 一覧にビル名リンクと操作列を足す**

`resources/views/tenant/area-buildings/index.blade.php` を 3 箇所直す。

(a) `<colgroup>` に 1 列足し、幅を配り直す:

```blade
                    <colgroup>
                        <col style="width:20%">
                        <col style="width:22%">
                        <col style="width:7%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:9%">
                        <col style="width:12%">
                        <col style="width:12%">
                    </colgroup>
```

(b) `<thead>` の「最終調査」`<th>` の**直後**に追加:

```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
```

(c) ビル名セルをリンクに変え、行の末尾に操作セルを足す:

```blade
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200">
                                    <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                       class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                                        {{ $row['building']->name }}
                                    </a>
                                </td>
```

最終調査セルの直後（`</tr>` の直前）に:

```blade
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                       class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                        詳細
                                    </a>
                                </td>
```

(d) 空行の `colspan="8"` を `colspan="9"` に変える。

- [ ] **Step 7: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter 'AreaBuildingShowTest|AreaBuildingListTest'
```

Expected: PASS（Show 11 tests + List 12 tests）

- [ ] **Step 8: 変異テストで 4 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `divergence()` の `$activeTenants->isEmpty()` の判定を削除 | `test_no_warning_when_there_are_no_tenant_rows` が赤 |
| 2 | `divergence()` の `$input === $counted ? null : ...` を `['input' => ..., 'counted' => ...]` 固定に | `test_no_warning_when_counts_agree` が赤 |
| 3 | `show()` の `$activeTenants` を `$tenants` に（退去済みを含める） | `test_moved_out_tenants_are_excluded_from_the_counted_side` が赤 |
| 4 | 詳細ビューに `<script src="https://maps.googleapis.com/maps/api/js?key=x"></script>` を 1 行足す | `test_detail_does_not_load_the_maps_javascript_api` が赤 |

- [ ] **Step 9: 警告ブロックの文言が false-pass しないことを確認する（Bug #43 型）**

- [ ] 変異 2 を当てたまま、`test_divergence_warning_appears_when_counts_disagree` の
      `$response->assertSee('調査時の実測とテナント明細が一致していません');` を
      `$response->assertSee('3');` に差し替える → **緑のまま**になることを確認する
      （数字は調査履歴の行にも出るため）。確認したら元に戻す。

- [ ] **Step 10: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の詳細画面を追加`

---

## Task 8: ビル登録・編集・削除（地図＋ジオコーディング）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingCrudTest.php`
- Create: `resources/views/tenant/area-buildings/create.blade.php`
- Create: `resources/views/tenant/area-buildings/edit.blade.php`
- Create: `resources/views/tenant/area-buildings/_form.blade.php`
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`（新規登録ボタン＋編集ボタン）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingCrudTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /**
     * ⚠ /create が {building} の ID として解釈されないこと（設計 §5.1 / §11-10）。
     *   ルート宣言の順序が逆だとモデルバインディングで 404 になる。
     */
    public function test_create_route_is_not_swallowed_by_the_show_route(): void
    {
        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings/create');

        $response->assertOk();
        $response->assertViewIs('tenant.area-buildings.create');
    }

    public function test_unknown_id_is_404(): void
    {
        $this->actingAs($this->staff())->get('/tenant/area-buildings/999999')->assertNotFound();
    }

    // ============================================================
    // 権限（設計 §8）
    // ============================================================

    public function test_staff_cannot_reach_create_store_edit_update_or_destroy(): void
    {
        $building = $this->makeBuilding('既存ビル');
        $staff    = $this->staff();

        $this->actingAs($staff)->get('/tenant/area-buildings/create')->assertForbidden();
        $this->actingAs($staff)->post('/tenant/area-buildings', ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/edit")->assertForbidden();
        $this->actingAs($staff)->put("/tenant/area-buildings/{$building->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}")->assertForbidden();
    }

    public function test_manager_can_edit_but_cannot_delete(): void
    {
        $building = $this->makeBuilding('既存ビル');
        $manager  = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$building->id}/edit")->assertOk();
        $this->actingAs($manager)->delete("/tenant/area-buildings/{$building->id}")->assertForbidden();
    }

    public function test_executive_can_delete_and_it_is_soft(): void
    {
        $building = $this->makeBuilding('消すビル');

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}")
            ->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSoftDeleted('area_buildings', ['id' => $building->id]);
    }

    // ============================================================
    // 登録
    // ============================================================

    public function test_store_creates_a_building_and_records_the_creator(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/tenant/area-buildings', [
            'name'         => '新規ビル',
            'address'      => '愛媛県松山市大街道1-1',
            'total_floors' => 6,
            'latitude'     => '33.8392000',
            'longitude'    => '132.7657000',
            'notes'        => 'メモ',
        ])->assertRedirect();

        $building = AreaBuilding::where('name', '新規ビル')->firstOrFail();
        $this->assertSame('愛媛県松山市大街道1-1', $building->address);
        $this->assertSame(6, $building->total_floors);
        $this->assertSame($manager->id, $building->created_by);
        $this->assertSame(0, $building->surveys()->count(), '調査年月が空なのに調査回が作られている');
    }

    /** 新規登録時のみ、同じ画面で 1 回目の調査も作れる（設計 §5.5） */
    public function test_store_can_create_the_first_survey_at_the_same_time(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/tenant/area-buildings', [
            'name'            => '初回調査つきビル',
            'surveyed_month'  => '2026-08',
            'operating_count' => 7,
            'vacant_count'    => 2,
            'unknown_count'   => 1,
            'survey_notes'    => '1階は改装中',
        ])->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame([7, 2, 1], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
        $this->assertSame('1階は改装中', $survey->notes);
        $this->assertSame($manager->id, $survey->surveyed_by, '調査者の既定がログインユーザーになっていない');
    }

    /** 件数欄は空欄スタート。未入力は 0 として保存する（設計 §5.5） */
    public function test_blank_counts_are_saved_as_zero(): void
    {
        $this->actingAs($this->manager())->post('/tenant/area-buildings', [
            'name'           => '件数空欄ビル',
            'surveyed_month' => '2026-08',
        ])->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame([0, 0, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    public function test_name_is_required_and_the_error_says_building_name_in_japanese(): void
    {
        $response = $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/create')
            ->post('/tenant/area-buildings', ['name' => '']);

        $response->assertRedirect('/tenant/area-buildings/create');
        $response->assertSessionHasErrors(['name' => 'ビル名は必ず入力してください。']);
    }

    /** 所在地はグローバルの「住所」でなく画面ラベルの「所在地」（Bug #37） */
    public function test_address_error_says_shozaichi_not_juusho(): void
    {
        $response = $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/create')
            ->post('/tenant/area-buildings', ['name' => 'X', 'address' => str_repeat('あ', 256)]);

        $response->assertSessionHasErrorsIn('default', ['address']);
        $this->assertStringContainsString('所在地', session('errors')->first('address'));
        $this->assertStringNotContainsString('住所', session('errors')->first('address'));
    }

    // ============================================================
    // 更新
    // ============================================================

    public function test_update_changes_the_building_and_never_touches_surveys(): void
    {
        $building = $this->makeBuilding('旧名', ['address' => '旧住所']);
        $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->manager())->put("/tenant/area-buildings/{$building->id}", [
            'name'    => '新名',
            'address' => '新住所',
            // ⚠ 編集画面に調査欄は出さない。送っても無視されることを固定する
            'surveyed_month'  => '2026-09',
            'operating_count' => 99,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $building->refresh();
        $this->assertSame('新名', $building->name);
        $this->assertSame('新住所', $building->address);
        $this->assertSame(1, $building->surveys()->count(), '編集で調査回が増えている');
        $this->assertSame(5, $building->surveys()->first()->operating_count);
    }

    /** 編集フォームは保存済みの座標を hidden に載せる（地図の初期表示に使う） */
    public function test_edit_form_carries_the_saved_coordinates(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/edit")
            ->assertOk()
            ->assertSee('value="33.8392000"', false)
            ->assertSee('value="132.7657000"', false);
    }

    // ============================================================
    // 地図（費用最小化。設計 §6.0）
    // ============================================================

    /** ⚠ Street View のコントロールを出すと、開いた回数だけ課金される */
    public function test_form_disables_street_view_control(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        $this->assertStringContainsString('streetViewControl: false', $html);
        $this->assertStringNotContainsString('streetViewControl: true', $html);
    }

    /** 地図は「マップで確認」を押したときだけ生成する（読み込んだだけでは new しない） */
    public function test_form_creates_the_map_only_on_demand(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        // bootstrap は読み込む（Geocoder が要る）
        $this->assertStringContainsString('maps.googleapis.com/maps/api/js', $html);

        // new google.maps.Map は showAreaMap() の中だけ。onGoogleMapsReady では作らない
        $this->assertSame(1, substr_count($html, 'new google.maps.Map('), 'Map を生成する箇所が 1 つでない');
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingCrudTest
```

Expected: FAIL — 404 / 405

- [ ] **Step 3: コントローラに create / store / edit / update / destroy を足す**

`app/Http/Controllers/Tenant/AreaBuildingController.php` に追加（`use Illuminate\Support\Facades\Auth;` を足す）:

```php
    public function create()
    {
        return view('tenant.area-buildings.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate(
            array_merge($this->buildingRules(), [
                // 新規登録時のみ 1 回目の調査を同時に作れる（設計 §5.5）。
                // ⚠ 所見は survey_notes。ビル自身の notes と衝突するため名前を分けている
                'surveyed_month'  => 'nullable|date_format:Y-m',
                'operating_count' => 'nullable|integer|min:0|max:9999',
                'vacant_count'    => 'nullable|integer|min:0|max:9999',
                'unknown_count'   => 'nullable|integer|min:0|max:9999',
                'survey_notes'    => 'nullable|string|max:2000',
            ]),
            [],
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            $this->buildingAttributes()
        );

        $building = AreaBuilding::create([
            'name'         => $validated['name'],
            'address'      => $validated['address'] ?? null,
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
            'created_by'   => Auth::id(),
        ]);

        if (filled($validated['surveyed_month'] ?? null)) {
            AreaBuildingSurvey::create([
                'area_building_id' => $building->id,
                'surveyed_month'   => $validated['surveyed_month'] . '-01',
                // 件数欄は空欄スタート。未入力は 0 として保存する
                'operating_count'  => $validated['operating_count'] ?? 0,
                'vacant_count'     => $validated['vacant_count'] ?? 0,
                'unknown_count'    => $validated['unknown_count'] ?? 0,
                'surveyed_by'      => Auth::id(),
                'notes'            => $validated['survey_notes'] ?? null,
            ]);
        }

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビルを登録しました。');
    }

    public function edit(AreaBuilding $building)
    {
        return view('tenant.area-buildings.edit', ['building' => $building]);
    }

    public function update(Request $request, AreaBuilding $building)
    {
        // ⚠ 編集画面に調査欄は出さない（調査は履歴側で管理する。設計 §5.5）。
        //   buildingRules() だけを通すので、調査の項目が送られてきても validated に入らない。
        $validated = $request->validate($this->buildingRules(), [], $this->buildingAttributes());

        $building->update([
            'name'         => $validated['name'],
            'address'      => $validated['address'] ?? null,
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビル情報を更新しました。');
    }

    public function destroy(AreaBuilding $building)
    {
        // SoftDeletes。調査回とテナントは FK CASCADE だが、ビル行が残るので子も残る
        // （復元可能にするための意図どおりの挙動。設計 §8）
        $building->delete();

        return redirect()->route('tenant.area-buildings.index')
            ->with('success', 'ビルを削除しました。');
    }

    /** @return array<string, string> */
    private function buildingRules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'total_floors' => 'nullable|integer|min:0|max:200',
            'notes'        => 'nullable|string|max:5000',
        ];
    }

    /**
     * 画面ラベルに合わせた項目名。
     * ⚠ グローバルは name=名称 / address=住所 のままにする（他画面が使っている）。
     */
    private function buildingAttributes(): array
    {
        return [
            'name'    => 'ビル名',
            'address' => '所在地',
        ];
    }
```

- [ ] **Step 4: ルートを足す**

Task 7 で入れた「⚠ /area-buildings/create /import /geocode はこの行より上に置くこと」の
コメントの**直前**に create / store を、`show` ルートの**直後**に edit / update / destroy を置く:

```php
        // 登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/area-buildings/create', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'create'])
                ->name('tenant.area-buildings.create');
            Route::post('/area-buildings', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'store'])
                ->name('tenant.area-buildings.store');
        });

        // ⚠ /area-buildings/import /geocode はこの行より上に置くこと

        // 詳細（全ロール閲覧可）
        Route::get('/area-buildings/{building}', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'show'])
            ->name('tenant.area-buildings.show');

        // 編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/area-buildings/{building}/edit', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'edit'])
                ->name('tenant.area-buildings.edit');
            Route::put('/area-buildings/{building}', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'update'])
                ->name('tenant.area-buildings.update');
        });

        // 削除（経営層のみ）
        Route::delete('/area-buildings/{building}', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.area-buildings.destroy');
```

- [ ] **Step 5: `_form.blade.php` を書く**

`resources/views/tenant/area-buildings/_form.blade.php`。
`realestate/procurements/_form.blade.php` の地図パーツを移植する（本番で枯れているコード）。
変更点は 2 つだけ: **`streetViewControl: false`**（§6.0）と、識別子を `area` 系に付け替えること。

⚠ `<script>` 内の `//` コメントに `@json` `@if` などのディレクティブ名を書かないこと。
書く必要があれば `@@json` とエスケープする（Bug #30）。

```blade
{{-- 期待: $building（編集時のみ）。create からは未定義で来る --}}
@php($b = $building ?? null)

<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ビル情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">ビル名<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" name="name" value="{{ old('name', $b?->name) }}" required maxlength="255"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">総階数</label>
            <input type="number" name="total_floors" value="{{ old('total_floors', $b?->total_floors) }}" inputmode="numeric" min="0" max="200"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">所在地</label>
            <input type="text" name="address" value="{{ old('address', $b?->address) }}" maxlength="255" placeholder="愛媛県松山市…"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="3"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $b?->notes) }}</textarea>
        </div>
    </div>
</div>

{{-- 所在地マップ --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">所在地マップ</div>
    <input type="hidden" name="latitude" id="input-latitude" value="{{ old('latitude', $b?->latitude) }}">
    <input type="hidden" name="longitude" id="input-longitude" value="{{ old('longitude', $b?->longitude) }}">

    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
        <button type="button" id="btn-geocode" onclick="geocodeAreaAddress()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            マップで確認
        </button>
        <span class="text-xs text-gray-500">住所からピン位置を検索します。空欄でも地図上でピンを配置できます</span>
    </div>

    <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

    {{-- ⚠ 緯度だけでなく経度も見る。Task 4 の hasCoordinates() を使う（片方だけ入った行で
         地図枠だけ出て中身が描画されない状態を防ぐ） --}}
    <div id="map-wrap" style="display: {{ $b?->hasCoordinates() ? 'block' : 'none' }};">
        <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
            <div id="area-building-map" data-map-fallback style="height: 350px; max-width: 100%;"></div>
        </div>
        <div class="flex gap-2" style="margin-top: 6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span class="text-xs text-gray-500">ピンをドラッグ、またはマップ上をクリックして正確な位置に調整できます</span>
        </div>
        <div class="flex gap-3" style="margin-top: 6px;">
            <span class="text-xs text-gray-500">緯度: <strong class="text-gray-800" id="display-lat">—</strong></span>
            <span class="text-xs text-gray-500">経度: <strong class="text-gray-800" id="display-lng">—</strong></span>
        </div>
    </div>
</div>

@isset($withInitialSurvey)
    {{-- 初回調査（新規登録時のみ。編集画面には出さない） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">1 回目の調査（任意）</div>
        <p class="text-xs text-gray-500 mb-3">調査年月を入れると、このビルの調査回を 1 件同時に作成します。あとから追加することもできます。</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月</label>
                <input type="month" name="surveyed_month" value="{{ old('surveyed_month') }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div></div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">営業</label>
                {{-- ⚠ value="0" の既定値を入れない（空欄スタートが原則）。未入力は 0 として保存する --}}
                <input type="number" name="operating_count" value="{{ old('operating_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">空き</label>
                <input type="number" name="vacant_count" value="{{ old('vacant_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">不明</label>
                <input type="number" name="unknown_count" value="{{ old('unknown_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">所見</label>
                <textarea name="survey_notes" rows="2"
                          class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('survey_notes') }}</textarea>
            </div>
        </div>
    </div>
@endisset

<script>
// ============================================================
// Google Maps — 所在地マップ
// realestate/procurements/_form.blade.php の移植。相違点は streetViewControl のみ。
// ============================================================
var areaMap = null;
var areaMarker = null;
var areaGeocoder = null;

// 既定の中心位置（松山市役所付近）— 住所空欄/全失敗時のフォールバック
var AREA_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

function onGoogleMapsReady() {
    areaGeocoder = new google.maps.Geocoder();

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showAreaMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 住所を段階的に短くしてフォールバック候補を生成
// 例: "愛媛県松山市勝山町2丁目4-7" → [フル, 番地除去, 丁目除去, 市区町村, 都道府県]
function buildAreaAddressFallbacks(address) {
    var candidates = [{ address: address, level: 'full', zoom: 17 }];

    var stripped = address
        .replace(/[\d０-９]+(?:[-‐−ー－―][\d０-９]+)+(?:号)?$/, '')
        .replace(/[\d０-９]+番地?(?:[\d０-９]+号?)?$/, '')
        .trim();
    if (stripped && stripped !== address) {
        candidates.push({ address: stripped, level: 'block', zoom: 16 });
    }

    stripped = address.replace(/[\d０-９]+丁目.*$/, '').trim();
    if (stripped && !candidates.some(function(c) { return c.address === stripped; })) {
        candidates.push({ address: stripped, level: 'town', zoom: 15 });
    }

    var cityMatch = address.match(/^.*?[市区町村]/);
    if (cityMatch && !candidates.some(function(c) { return c.address === cityMatch[0]; })) {
        candidates.push({ address: cityMatch[0], level: 'city', zoom: 13 });
    }

    var prefMatch = address.match(/^.*?[都道府県]/);
    if (prefMatch && !candidates.some(function(c) { return c.address === prefMatch[0]; })) {
        candidates.push({ address: prefMatch[0], level: 'prefecture', zoom: 10 });
    }

    return candidates;
}

function tryGeocodeAreaCandidates(candidates, index, callback) {
    if (index >= candidates.length) { callback(null); return; }
    var candidate = candidates[index];
    areaGeocoder.geocode({ address: candidate.address }, function(results, status) {
        if (status === 'OK' && results[0]) {
            callback({
                location: results[0].geometry.location,
                level: candidate.level,
                zoom: candidate.zoom,
                matchedAddress: candidate.address
            });
        } else {
            tryGeocodeAreaCandidates(candidates, index + 1, callback);
        }
    });
}

// 手作業の 1 棟ずつ用。1 クリックで最大 5 回ジオコーディングを叩く。
// 一括処理でこの関数を使い回さないこと（設計 §6.1 / §7.4）。
function geocodeAreaAddress() {
    var addressInput = document.querySelector('input[name="address"]');
    var address = addressInput ? addressInput.value.trim() : '';

    if (!areaGeocoder) {
        showAreaMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    if (!address) {
        showAreaMapStatus('所在地が空欄です。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
        showAreaMap(AREA_DEFAULT_CENTER.lat, AREA_DEFAULT_CENTER.lng, AREA_DEFAULT_CENTER.zoom);
        return;
    }

    showAreaMapStatus('住所を検索中...', '#fef3c7', '#92400e');
    document.getElementById('btn-geocode').disabled = true;

    tryGeocodeAreaCandidates(buildAreaAddressFallbacks(address), 0, function(result) {
        document.getElementById('btn-geocode').disabled = false;

        if (result) {
            if (result.level === 'full') {
                showAreaMapStatus('住所が見つかりました。ピンをドラッグして正確な位置に調整できます。', '#d1fae5', '#065f46');
            } else {
                showAreaMapStatus('「' + result.matchedAddress + '」までヒットしました。地図をクリックして正確な位置を指定してください。', '#fef3c7', '#92400e');
            }
            showAreaMap(result.location.lat(), result.location.lng(), result.zoom);
        } else {
            showAreaMapStatus('住所が見つかりませんでした。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#fef3c7', '#92400e');
            showAreaMap(AREA_DEFAULT_CENTER.lat, AREA_DEFAULT_CENTER.lng, AREA_DEFAULT_CENTER.zoom);
        }
    });
}

function showAreaMapStatus(msg, bg, color) {
    var el = document.getElementById('map-status');
    el.style.display = 'block';
    el.style.background = bg;
    el.style.color = color;
    el.textContent = msg;
}

function showAreaMap(lat, lng, zoom) {
    document.getElementById('map-wrap').style.display = 'block';

    if (typeof zoom !== 'number') zoom = 17;

    if (!areaMap) {
        areaMap = new google.maps.Map(document.getElementById('area-building-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            // Street View を開いた回数だけ課金されるのでコントロールを出さない（設計 §6.0）
            streetViewControl: false,
            fullscreenControl: false
        });

        areaMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: areaMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        areaMarker.addListener('dragend', function() {
            var pos = areaMarker.getPosition();
            updateAreaCoords(pos.lat(), pos.lng());
        });

        areaMap.addListener('click', function(e) {
            areaMarker.setPosition(e.latLng);
            updateAreaCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        areaMap.setCenter({ lat: lat, lng: lng });
        areaMap.setZoom(zoom);
        areaMarker.setPosition({ lat: lat, lng: lng });
    }

    updateAreaCoords(lat, lng);
}

function updateAreaCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17） --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
```

- [ ] **Step 6: `create.blade.php` と `edit.blade.php` を書く**

`resources/views/tenant/area-buildings/create.blade.php`:

```blade
@extends('layouts.app')

@section('title', '周辺ビル 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

    <a href="{{ route('tenant.area-buildings.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        周辺ビル調査に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">周辺ビル 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('tenant.area-buildings.store') }}">
        @csrf
        @include('tenant.area-buildings._form', ['withInitialSurvey' => true])
        <x-form-actions submit-label="登録する" :cancel-url="route('tenant.area-buildings.index')" />
    </form>

@endsection
```

`resources/views/tenant/area-buildings/edit.blade.php` は上と同じ構造で、次の 4 点だけ違う:

- `@section('title', 'ビル情報の編集')` / パンくずの末尾を `{{ $building->name }}`
- 戻るリンクと `x-form-actions` の cancel-url は `route('tenant.area-buildings.show', $building)`
- `<form method="POST" action="{{ route('tenant.area-buildings.update', $building) }}">` の直下に `@method('PUT')`
- `@include('tenant.area-buildings._form', ['building' => $building])`（**`withInitialSurvey` は渡さない**）

⚠ `x-form-actions` の `:cancel-url` に `&quot;` を書かない（本番の `view:cache` で 500 になる。Bug #21）。
上の形（シングルクォートの静的ルート名 ＋ 変数）なら安全。

- [ ] **Step 7: 一覧に新規登録ボタンと編集ボタンを足す**

`index.blade.php` のページヘッダーの `<h1>` の直後（`</div>` の前）:

```blade
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.area-buildings.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規登録
            </a>
        @endif
```

操作セルの「詳細」リンクの直後（同じ `<div class="flex gap-1.5 justify-center">` でくくり直す）:

```blade
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.area-buildings.edit', $row['building']) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
```

- [ ] **Step 8: 詳細画面に編集・削除ボタンを足す**

`show.blade.php` のページヘッダー（`<h1>` を含む `div`）の中、`</div>` の直前:

```blade
        <div class="flex gap-2">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.area-buildings.edit', $building) }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2.5 border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-semibold rounded-md hover:bg-emerald-100 transition-colors">
                    編集
                </a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('tenant.area-buildings.destroy', $building) }}"
                      onsubmit="return confirm('このビルを削除します。調査履歴とテナント明細も画面から見えなくなります。よろしいですか？');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 border border-red-200 bg-red-50 text-red-700 text-sm font-semibold rounded-md hover:bg-red-100 transition-colors">
                        削除
                    </button>
                </form>
            @endif
        </div>
```

- [ ] **Step 9: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter 'AreaBuildingCrudTest|AreaBuildingShowTest|AreaBuildingListTest'
```

Expected: PASS（Crud 13 + Show 11 + List 12）

- [ ] **Step 10: 変異テストで 4 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | ルートの create / store ブロックを show ルートの**後**へ移動 | `test_create_route_is_not_swallowed_by_the_show_route` が赤（404） |
| 2 | `update()` の validate を `store()` と同じルール配列に変える | `test_update_changes_the_building_and_never_touches_surveys` が赤 |
| 3 | `buildingAttributes()` の `'address' => '所在地'` を削除 | `test_address_error_says_shozaichi_not_juusho` が赤 |
| 4 | `_form.blade.php` の `streetViewControl: false` を `true` に | `test_form_disables_street_view_control` が赤 |

- [ ] **Step 11: 本番同等のコンパイルを確認する（Bug #26 / #30）**

`view:cache` は成功表示してもコンパイル済み PHP を lint しない。必ず生成物を lint する:

```bash
APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan view:clear
```

Expected: `INVALID:` が 1 件も出ない

- [ ] **Step 12: コミット**

`/commit` で `feat(tenant): 周辺ビルの登録・編集・削除を追加`

---

## Task 9: 調査回の追加・編集・削除（別画面）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingSurveyCrudTest.php`
- Create: `app/Http/Controllers/Tenant/AreaBuildingSurveyController.php`
- Create: `resources/views/tenant/area-buildings/surveys/create.blade.php` / `edit.blade.php` / `_form.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/show.blade.php`（「調査を追加」＋行ごとの編集・削除）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingSurveyCrudTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuildingSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingSurveyCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_a_survey(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();

        $this->actingAs($manager)->post("/tenant/area-buildings/{$building->id}/surveys", [
            'surveyed_month'  => '2026-08',
            'operating_count' => 7,
            'vacant_count'    => 2,
            'unknown_count'   => 1,
            'notes'           => '1階は改装中',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame($manager->id, $survey->surveyed_by, '調査者の既定がログインユーザーになっていない');
    }

    /** 調査者は変更できる（現地を歩いた担当と入力者が違うことがある。設計 §3.2） */
    public function test_surveyor_can_be_someone_else(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/surveys", [
            'surveyed_month' => '2026-08',
            'surveyed_by'    => $walker->id,
        ])->assertRedirect();

        $this->assertSame($walker->id, AreaBuildingSurvey::firstOrFail()->surveyed_by);
    }

    /** 件数欄は空欄スタート。未入力は 0 */
    public function test_blank_counts_become_zero(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08'])
            ->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame([0, 0, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    /**
     * 同じビルの同じ年月は 1 件。上書きせず確認を出す（設計 §3.2）。
     * ⚠ 500（UNIQUE 違反）ではなくバリデーションエラーで返すこと。
     */
    public function test_duplicate_month_is_rejected_with_a_validation_error(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeSurvey($building, '2026-08-01', 5, 5);

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08']);

        $response->assertRedirect(route('tenant.area-buildings.surveys.create', $building));
        $response->assertSessionHasErrors('surveyed_month');
        $this->assertStringContainsString('既に登録されています', session('errors')->first('surveyed_month'));
        $this->assertSame(1, $building->surveys()->count());
    }

    /** 月中の日付で送っても月初に正規化されるので、同じ月として弾かれる */
    public function test_duplicate_detection_is_month_based(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeSurvey($building, '2026-08-20', 5, 5);   // → 2026-08-01 に正規化済み

        $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08'])
            ->assertSessionHasErrors('surveyed_month');
    }

    /** 自分自身は重複判定から除外する（年月を変えずに件数だけ直せる） */
    public function test_update_can_keep_the_same_month(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
                'surveyed_month'  => '2026-08',
                'operating_count' => 8,
                'vacant_count'    => 2,
            ])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(8, $survey->fresh()->operating_count);
    }

    /**
     * 他のビルの調査回に URL を差し替えて到達できないこと。
     * ⚠ ミドルウェアは部門単位でしか見ないので、所有権はコントローラで明示的に確かめる
     *   （部署共通コントローラの IDOR と同型）。
     */
    public function test_survey_of_another_building_is_404(): void
    {
        $mine   = $this->makeBuilding('自分のビル');
        $others = $this->makeBuilding('別のビル');
        $survey = $this->makeSurvey($others, '2026-08-01', 5, 5);

        $manager = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}/edit")->assertNotFound();
        $this->actingAs($manager)->put("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}", ['surveyed_month' => '2026-09'])->assertNotFound();
        $this->actingAs($this->executive())->delete("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}")->assertNotFound();

        $this->assertSame(1, $others->surveys()->count(), '別ビルの調査回が消えている');
    }

    public function test_permissions(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);
        $staff    = $this->staff();

        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/surveys/create")->assertForbidden();
        $this->actingAs($staff)->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-09'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")->assertForbidden();

        // 削除は経営層のみ
        $this->actingAs($this->manager())->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")->assertForbidden();
    }

    /** 調査回は物理削除（SoftDeletes を持たない。設計 §3.2） */
    public function test_executive_can_hard_delete_a_survey(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_surveys', ['id' => $survey->id]);
    }

    /** 所見のエラー文言は「備考」でなく「所見」（第3引数の上書きが効いていること） */
    public function test_notes_error_says_shoken(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", [
                'surveyed_month' => '2026-08',
                'notes'          => str_repeat('あ', 2001),
            ]);

        $this->assertStringContainsString('所見', session('errors')->first('notes'));
    }

    /**
     * 調査者セレクトは編集時に現在の調査者を必ず含める（無効化済みでも消えない。Bug #12）。
     * ⚠ option は @@foreach で静的に生成する（x-for は使わない。Bug #16）。
     */
    public function test_edit_form_keeps_a_deactivated_surveyor_in_the_options(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $retired  = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $retired->id]);

        $retired->delete();   // 退職（SoftDeletes）

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")
            ->getContent();

        $this->assertStringContainsString('value="' . $retired->id . '"', $html, '退職者が選択肢から消えている');
        $this->assertStringNotContainsString('x-for', $html, 'option を x-for で生成している（Bug #16）');
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingSurveyCrudTest
```

Expected: FAIL — 404

- [ ] **Step 3: コントローラを書く**

`app/Http/Controllers/Tenant/AreaBuildingSurveyController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビルの調査回。Ajax ではなく別画面で追加・編集する（設計 §5.6）。
 */
class AreaBuildingSurveyController extends Controller
{
    public function create(AreaBuilding $building)
    {
        return view('tenant.area-buildings.surveys.create', [
            'building'  => $building,
            'survey'    => null,
            'surveyors' => User::assignable()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AreaBuilding $building)
    {
        $validated = $request->validate($this->rules(), [], $this->attributes());

        $month = $validated['surveyed_month'] . '-01';

        if ($this->monthTaken($building, $month, null)) {
            return back()->withInput()->withErrors([
                'surveyed_month' => 'この年月の調査は既に登録されています。上書きせず、既存の調査を編集してください。',
            ]);
        }

        AreaBuildingSurvey::create($this->payload($validated, $month, $building));

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を登録しました。');
    }

    public function edit(AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        return view('tenant.area-buildings.surveys.edit', [
            'building'  => $building,
            'survey'    => $survey,
            // 現在の調査者が無効化・削除済みでも選択肢に残す（Bug #12）
            'surveyors' => User::assignableWith($survey->surveyed_by),
        ]);
    }

    public function update(Request $request, AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        $validated = $request->validate($this->rules(), [], $this->attributes());

        $month = $validated['surveyed_month'] . '-01';

        if ($this->monthTaken($building, $month, $survey->id)) {
            return back()->withInput()->withErrors([
                'surveyed_month' => 'この年月の調査は既に登録されています。上書きせず、既存の調査を編集してください。',
            ]);
        }

        $survey->update($this->payload($validated, $month, $building));

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を更新しました。');
    }

    public function destroy(AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        // 調査回は物理削除（SoftDeletes を持たない。設計 §3.2）
        $survey->delete();

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を削除しました。');
    }

    /**
     * ⚠ ミドルウェアは部門単位でしか見ない。URL の {building} と {survey} の
     *   親子関係はここで明示的に確かめる（付け忘れると別ビルの調査回を編集・削除できる）。
     */
    private function assertOwnedBy(AreaBuilding $building, AreaBuildingSurvey $survey): void
    {
        abort_unless($survey->area_building_id === $building->id, 404);
    }

    private function monthTaken(AreaBuilding $building, string $month, ?int $ignoreId): bool
    {
        // ⚠ whereDate で見る。date キャストは $dateFormat（既定 Y-m-d H:i:s）で書き込むので、
        //   型を持たない SQLite には '2026-08-01 00:00:00' が残りうる。= 比較だと
        //   本番 MySQL とテスト SQLite で挙動が割れる危険がある。
        return $building->surveys()
            ->whereDate('surveyed_month', $month)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function payload(array $validated, string $month, AreaBuilding $building): array
    {
        return [
            'area_building_id' => $building->id,
            'surveyed_month'   => $month,
            // 件数欄は空欄スタート。未入力は 0 として保存する（設計 §5.5）
            'operating_count'  => $validated['operating_count'] ?? 0,
            'vacant_count'     => $validated['vacant_count'] ?? 0,
            'unknown_count'    => $validated['unknown_count'] ?? 0,
            // 既定はログインユーザー。現地を歩いた担当が別なら変更できる
            'surveyed_by'      => $validated['surveyed_by'] ?? Auth::id(),
            'notes'            => $validated['notes'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'surveyed_month'  => 'required|date_format:Y-m',
            'operating_count' => 'nullable|integer|min:0|max:9999',
            'vacant_count'    => 'nullable|integer|min:0|max:9999',
            'unknown_count'   => 'nullable|integer|min:0|max:9999',
            'surveyed_by'     => 'nullable|integer|exists:users,id',
            'notes'           => 'nullable|string|max:2000',
        ];
    }

    /** ⚠ 第3引数が attributes（第2引数は messages）。Bug #37 */
    private function attributes(): array
    {
        return ['notes' => '所見'];
    }
}
```

- [ ] **Step 4: ルートを足す**

`tenant.area-buildings.destroy` の直後に:

```php
        // 調査回（追加・編集は経営層+管理者 / 削除は経営層）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/area-buildings/{building}/surveys/create', [\App\Http\Controllers\Tenant\AreaBuildingSurveyController::class, 'create'])
                ->name('tenant.area-buildings.surveys.create');
            Route::post('/area-buildings/{building}/surveys', [\App\Http\Controllers\Tenant\AreaBuildingSurveyController::class, 'store'])
                ->name('tenant.area-buildings.surveys.store');
            Route::get('/area-buildings/{building}/surveys/{survey}/edit', [\App\Http\Controllers\Tenant\AreaBuildingSurveyController::class, 'edit'])
                ->name('tenant.area-buildings.surveys.edit');
            Route::put('/area-buildings/{building}/surveys/{survey}', [\App\Http\Controllers\Tenant\AreaBuildingSurveyController::class, 'update'])
                ->name('tenant.area-buildings.surveys.update');
        });
        Route::delete('/area-buildings/{building}/surveys/{survey}', [\App\Http\Controllers\Tenant\AreaBuildingSurveyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.area-buildings.surveys.destroy');
```

- [ ] **Step 5: 調査フォームのビューを書く**

`resources/views/tenant/area-buildings/surveys/_form.blade.php`:

```blade
{{-- 期待: $building / $survey（新規は null）/ $surveyors --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">調査内容</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月<span class="text-red-600 ml-0.5">*</span></label>
            <input type="month" name="surveyed_month" required
                   value="{{ old('surveyed_month', $survey?->monthInputValue()) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">調査者</label>
            {{-- ⚠ option は @@foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            <select name="surveyed_by"
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">— 未指定（登録者になります）—</option>
                @foreach($surveyors as $surveyor)
                    <option value="{{ $surveyor->id }}"
                        {{ (string) old('surveyed_by', $survey?->surveyed_by ?? auth()->id()) === (string) $surveyor->id ? 'selected' : '' }}>
                        {{ $surveyor->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">営業</label>
            <input type="number" name="operating_count" value="{{ old('operating_count', $survey?->operating_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">空き</label>
            <input type="number" name="vacant_count" value="{{ old('vacant_count', $survey?->vacant_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">不明</label>
            <input type="number" name="unknown_count" value="{{ old('unknown_count', $survey?->unknown_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div></div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">所見</label>
            <textarea name="notes" rows="3"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $survey?->notes) }}</textarea>
        </div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        空室率は「（空き ＋ 不明）÷（営業 ＋ 空き ＋ 不明）」で算出します。「不明」は空きとして数えます。
    </p>
</div>
```

`resources/views/tenant/area-buildings/surveys/create.blade.php`:

```blade
@extends('layouts.app')

@section('title', '調査を追加')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.show', $building) }}" class="hover:text-emerald-600 transition-colors">{{ $building->name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">調査を追加</span>
@endsection

@section('content')

    <a href="{{ route('tenant.area-buildings.show', $building) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        {{ $building->name }} に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">{{ $building->name }} — 調査を追加</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('tenant.area-buildings.surveys.store', $building) }}">
        @csrf
        @include('tenant.area-buildings.surveys._form')
        <x-form-actions submit-label="登録する" :cancel-url="route('tenant.area-buildings.show', $building)" />
    </form>

@endsection
```

`edit.blade.php` は同じ構造で、次の 3 点だけ違う:
- `@section('title', '調査を編集')` / 見出しとパンくずの末尾を「調査を編集」
- `<form ... action="{{ route('tenant.area-buildings.surveys.update', [$building, $survey]) }}">` ＋ 直下に `@method('PUT')`
- `<x-form-actions submit-label="更新する" ... />`

- [ ] **Step 6: 詳細画面に導線を足す**

`show.blade.php` の調査履歴カードのヘッダー（`<div class="text-sm font-bold text-gray-800">調査履歴</div>` の隣）:

```blade
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.area-buildings.surveys.create', $building) }}"
                   class="text-xs font-semibold text-emerald-700 px-3 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">
                    調査を追加
                </a>
            @endif
```

調査履歴テーブルに操作列を足す（`<colgroup>` の最後に `<col style="width:12%">` を足し、
「所見」の幅を `32%` → `20%` に、`<thead>` の末尾に `操作` の `<th>`、各行の末尾に）:

```blade
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.area-buildings.surveys.edit', [$building, $survey]) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">編集</a>
                                        @endif
                                        @if(auth()->user()->role->isExecutive())
                                            <form method="POST" action="{{ route('tenant.area-buildings.surveys.destroy', [$building, $survey]) }}"
                                                  onsubmit="return confirm('{{ $survey->monthLabel() }} の調査を削除します。よろしいですか？');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="text-xs font-semibold text-red-700 px-3 py-1 border border-red-200 rounded bg-red-50 hover:bg-red-100 transition-colors">削除</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
```

空行の `colspan="7"` を `colspan="8"` に変える。

- [ ] **Step 7: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter 'AreaBuildingSurveyCrudTest|AreaBuildingShowTest'
```

Expected: PASS（Survey 11 + Show 11）

- [ ] **Step 8: 変異テストで 4 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `assertOwnedBy()` の中身を空にする | `test_survey_of_another_building_is_404` が赤 |
| 2 | `monthTaken()` の `when($ignoreId !== null, ...)` を削除 | `test_update_can_keep_the_same_month` が赤 |
| 3 | `monthTaken()` の `whereDate` を `where('surveyed_month', $month)` に | **測って記録する。** Laravel の `date` キャストは `$dateFormat`（既定 `Y-m-d H:i:s`）で書き込む。MySQL の DATE 列は日付に切り詰めるが、SQLite は型が無いので `'2026-08-01 00:00:00'` が残りうる。残るなら `test_duplicate_month_is_rejected_with_a_validation_error` が赤、切り詰められるなら緑。**緑でも `whereDate` のままにする**（本番 MySQL とテスト SQLite で挙動が割れない書き方だから） |
| 4 | `edit()` の `User::assignableWith($survey->surveyed_by)` を `User::assignable()->get()` に | `test_edit_form_keeps_a_deactivated_surveyor_in_the_options` が赤 |

- [ ] **Step 9: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の調査回 CRUD を追加`

---

## Task 10: 入居テナントの追加・編集・削除（別画面）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingTenantCrudTest.php`
- Create: `app/Http/Controllers/Tenant/AreaBuildingTenantController.php`
- Create: `resources/views/tenant/area-buildings/tenants/create.blade.php` / `edit.blade.php` / `_form.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/show.blade.php`（「テナントを追加」＋行ごとの編集・削除）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingTenantCrudTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingTenantCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_a_tenant(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'floor'        => 3,
            'room_number'  => '301',
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'status'       => AreaTenantStatus::Operating->value,
            'confirmed_on' => '2026-08-10',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $tenant = AreaBuildingTenant::firstOrFail();
        $this->assertSame(3, $tenant->floor);
        $this->assertSame(AreaTenantStatus::Operating, $tenant->status);
        $this->assertSame($building->id, $tenant->area_building_id);
    }

    /** 地下は負数（B1 = -1） */
    public function test_basement_floor_is_stored_as_a_negative_number(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'floor'  => -1,
            'status' => AreaTenantStatus::Vacant->value,
        ])->assertRedirect();

        $this->assertSame('B1F', AreaBuildingTenant::firstOrFail()->floorLabel());
    }

    /**
     * 「保存して続けて登録」（設計 §5.6）。1 棟 10〜20 区画なので往復を減らす。
     * ⚠ keep_adding は validate() に載せない（項目名が要らないうえ、画面の入力ではないため）。
     */
    public function test_keep_adding_returns_to_the_create_screen(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'name'        => '1件目',
            'status'      => AreaTenantStatus::Operating->value,
            'keep_adding' => '1',
        ])->assertRedirect(route('tenant.area-buildings.tenants.create', $building));

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'name'   => '2件目',
            'status' => AreaTenantStatus::Operating->value,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(2, $building->tenants()->count());
    }

    /** 退去日を入れると現況リストから外れて履歴になる（行は消えない） */
    public function test_setting_moved_out_on_moves_the_row_to_history(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => '撤退カフェ']);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
                'name'         => '撤退カフェ',
                'status'       => AreaTenantStatus::Operating->value,
                'moved_out_on' => '2026-07-31',
            ])
            ->assertRedirect();

        $this->assertSame(0, $building->activeTenants()->count());
        $this->assertSame(1, $building->tenants()->count());
    }

    public function test_status_is_required_and_must_be_a_known_value(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.tenants.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/tenants", ['status' => 'closed'])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, $building->tenants()->count());
    }

    /** 項目名は画面ラベルどおり（第3引数の上書きが効いていること。Bug #37） */
    public function test_error_labels_match_the_screen(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.tenants.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/tenants", [
                'status'      => AreaTenantStatus::Operating->value,
                'name'        => str_repeat('あ', 256),
                'room_number' => str_repeat('A', 51),
            ]);

        $errors = session('errors');
        $this->assertStringContainsString('テナント名', $errors->first('name'));
        $this->assertStringNotContainsString('名称', $errors->first('name'));
        $this->assertStringContainsString('部屋番号', $errors->first('room_number'));
        $this->assertStringNotContainsString('号室', $errors->first('room_number'));
    }

    public function test_tenant_of_another_building_is_404(): void
    {
        $mine   = $this->makeBuilding('自分のビル');
        $others = $this->makeBuilding('別のビル');
        $tenant = $this->makeTenant($others, ['name' => '他所のテナント']);

        $manager = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}/edit")->assertNotFound();
        $this->actingAs($manager)->put("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}", ['status' => 'vacant'])->assertNotFound();
        $this->actingAs($this->executive())->delete("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}")->assertNotFound();

        $this->assertSame(1, $others->tenants()->count());
    }

    public function test_permissions(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);
        $staff    = $this->staff();

        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/tenants/create")->assertForbidden();
        $this->actingAs($staff)->post("/tenant/area-buildings/{$building->id}/tenants", ['status' => 'vacant'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")->assertForbidden();

        $this->actingAs($this->manager())->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")->assertForbidden();
    }

    public function test_executive_can_delete(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_tenants', ['id' => $tenant->id]);
    }

    /** ⚠ 状態セレクトの option は @@foreach で静的に生成する（Bug #16） */
    public function test_status_options_are_static(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/tenants/create")
            ->getContent();

        foreach (AreaTenantStatus::cases() as $case) {
            $this->assertStringContainsString('value="' . $case->value . '"', $html);
        }
        $this->assertStringNotContainsString('x-for', $html);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingTenantCrudTest
```

Expected: FAIL — 404

- [ ] **Step 3: コントローラを書く**

`app/Http/Controllers/Tenant/AreaBuildingTenantController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingTenant;
use Illuminate\Http\Request;

/**
 * 周辺ビルの入居テナント（現況リスト）。Ajax ではなく別画面で追加・編集する（設計 §5.6）。
 */
class AreaBuildingTenantController extends Controller
{
    public function create(AreaBuilding $building)
    {
        return view('tenant.area-buildings.tenants.create', [
            'building' => $building,
            'tenant'   => null,
        ]);
    }

    public function store(Request $request, AreaBuilding $building)
    {
        $validated = $request->validate($this->rules(), [], $this->attributes());

        AreaBuildingTenant::create(array_merge($validated, ['area_building_id' => $building->id]));

        // 「保存して続けて登録」。1 棟 10〜20 区画になるので往復を減らす（設計 §5.6）。
        // ⚠ validate() には載せない（項目名が要らないうえ、画面の入力項目ではない）
        if ($request->boolean('keep_adding')) {
            return redirect()->route('tenant.area-buildings.tenants.create', $building)
                ->with('success', 'テナントを登録しました。続けて登録できます。');
        }

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを登録しました。');
    }

    public function edit(AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        return view('tenant.area-buildings.tenants.edit', [
            'building' => $building,
            'tenant'   => $tenant,
        ]);
    }

    public function update(Request $request, AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        $validated = $request->validate($this->rules(), [], $this->attributes());

        // ⚠ 未送信キーは validated() に入らないので、null に落としたい列は明示的に埋める
        //   （x-show や任意項目で送られなかったとき旧値が残る事故を防ぐ。Bug #38）
        $tenant->update([
            'floor'        => $validated['floor'] ?? null,
            'room_number'  => $validated['room_number'] ?? null,
            'name'         => $validated['name'] ?? null,
            'industry'     => $validated['industry'] ?? null,
            'status'       => $validated['status'],
            'confirmed_on' => $validated['confirmed_on'] ?? null,
            'moved_out_on' => $validated['moved_out_on'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを更新しました。');
    }

    public function destroy(AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        $tenant->delete();

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを削除しました。');
    }

    /** ⚠ URL の {building} と {tenant} の親子関係を明示的に確かめる */
    private function assertOwnedBy(AreaBuilding $building, AreaBuildingTenant $tenant): void
    {
        abort_unless($tenant->area_building_id === $building->id, 404);
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        $statuses = implode(',', array_column(AreaTenantStatus::cases(), 'value'));

        return [
            'floor'        => 'nullable|integer|min:-10|max:200',
            'room_number'  => 'nullable|string|max:50',
            'name'         => 'nullable|string|max:255',
            'industry'     => 'nullable|string|max:100',
            'status'       => 'required|in:' . $statuses,
            'confirmed_on' => 'nullable|date',
            'moved_out_on' => 'nullable|date',
            'notes'        => 'nullable|string|max:2000',
        ];
    }

    /** ⚠ 第3引数が attributes（第2引数は messages）。Bug #37 */
    private function attributes(): array
    {
        return [
            'name'        => 'テナント名',
            'room_number' => '部屋番号',
            'floor'       => '階',
            'status'      => '状態',
        ];
    }
}
```

- [ ] **Step 4: ルートを足す**

調査回のルートの直後に、同じ形で `tenants` を追加する:

```php
        // 入居テナント（追加・編集は経営層+管理者 / 削除は経営層）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/area-buildings/{building}/tenants/create', [\App\Http\Controllers\Tenant\AreaBuildingTenantController::class, 'create'])
                ->name('tenant.area-buildings.tenants.create');
            Route::post('/area-buildings/{building}/tenants', [\App\Http\Controllers\Tenant\AreaBuildingTenantController::class, 'store'])
                ->name('tenant.area-buildings.tenants.store');
            Route::get('/area-buildings/{building}/tenants/{tenant}/edit', [\App\Http\Controllers\Tenant\AreaBuildingTenantController::class, 'edit'])
                ->name('tenant.area-buildings.tenants.edit');
            Route::put('/area-buildings/{building}/tenants/{tenant}', [\App\Http\Controllers\Tenant\AreaBuildingTenantController::class, 'update'])
                ->name('tenant.area-buildings.tenants.update');
        });
        Route::delete('/area-buildings/{building}/tenants/{tenant}', [\App\Http\Controllers\Tenant\AreaBuildingTenantController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.area-buildings.tenants.destroy');
```

- [ ] **Step 5: テナントフォームのビューを書く**

`resources/views/tenant/area-buildings/tenants/_form.blade.php`:

```blade
{{-- 期待: $building / $tenant（新規は null） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">テナント情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">階</label>
            <input type="number" name="floor" value="{{ old('floor', $tenant?->floor) }}" inputmode="numeric" min="-10" max="200" placeholder="地下は -1 のように負数で"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">部屋番号</label>
            <input type="text" name="room_number" value="{{ old('room_number', $tenant?->room_number) }}" maxlength="50"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">テナント名</label>
            <input type="text" name="name" value="{{ old('name', $tenant?->name) }}" maxlength="255" placeholder="空き区画は空欄のまま"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">業種</label>
            <input type="text" name="industry" value="{{ old('industry', $tenant?->industry) }}" maxlength="100"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">状態<span class="text-red-600 ml-0.5">*</span></label>
            {{-- ⚠ option は @@foreach で静的に生成する（Bug #16） --}}
            <select name="status" required
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                @foreach(\App\Enums\AreaTenantStatus::cases() as $case)
                    <option value="{{ $case->value }}"
                        {{ old('status', $tenant?->status?->value ?? \App\Enums\AreaTenantStatus::Operating->value) === $case->value ? 'selected' : '' }}>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">最終確認日</label>
            <input type="date" name="confirmed_on" value="{{ old('confirmed_on', $tenant?->confirmed_on?->format('Y-m-d')) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">退去日</label>
            <input type="date" name="moved_out_on" value="{{ old('moved_out_on', $tenant?->moved_out_on?->format('Y-m-d')) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            <p class="mt-1 text-xs text-gray-500">入れると現況リストから外れ、履歴として残ります。</p>
        </div>
        <div></div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="2"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $tenant?->notes) }}</textarea>
        </div>
    </div>
</div>
```

`create.blade.php` は Task 9 の調査 create と同じ骨格で、フォームの中身が次のとおり:

```blade
    <form method="POST" action="{{ route('tenant.area-buildings.tenants.store', $building) }}">
        @csrf
        @include('tenant.area-buildings.tenants._form')

        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" name="keep_adding" value="1" {{ old('keep_adding') ? 'checked' : '' }}
                       class="w-4 h-4 accent-emerald-600">
                保存して続けて登録する（このビルの追加画面に戻ります）
            </label>
        </div>

        <x-form-actions submit-label="登録する" :cancel-url="route('tenant.area-buildings.show', $building)" />
    </form>
```

`edit.blade.php` は `@method('PUT')` ＋ `tenants.update` ＋ `submit-label="更新する"`。
**「保存して続けて登録」のチェックボックスは編集画面には出さない。**

- [ ] **Step 6: 詳細画面に導線を足す**

入居テナントカードのヘッダーに「テナントを追加」ボタン（Task 9 の「調査を追加」と同形、
リンク先は `route('tenant.area-buildings.tenants.create', $building)`）。

テナント表に操作列を足す（`<colgroup>` の最後に `<col style="width:12%">` を足し「業種」を `18%` → `12%` に、
`<thead>` に `操作`、各行の末尾に編集・削除ボタン。調査履歴と同形で
`tenants.edit` / `tenants.destroy` を使い、`confirm()` の文言は
`'{{ $tenant->name ?: "この行" }} を削除します。よろしいですか？'`）。空行の `colspan="6"` を `colspan="7"` に変える。

⚠ `confirm()` の中で `{{ }}` を使うときは**シングルクォート内にダブルクォートを入れない**
（Blade コンポーネント属性ではないので Bug #21 には当たらないが、JS の文字列としては壊れる）。
名前に `'` が含まれる可能性を考えて `{{ addslashes($tenant->name ?: 'この行') }}` とする。

- [ ] **Step 7: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter 'AreaBuildingTenantCrudTest|AreaBuildingShowTest'
```

Expected: PASS（Tenant 10 + Show 11）

- [ ] **Step 8: 変異テストで 3 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `assertOwnedBy()` の中身を空にする | `test_tenant_of_another_building_is_404` が赤 |
| 2 | `store()` の `keep_adding` 分岐を削除 | `test_keep_adding_returns_to_the_create_screen` が赤 |
| 3 | `attributes()` の `'name' => 'テナント名'` を削除 | `test_error_labels_match_the_screen` が赤 |

- [ ] **Step 9: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の入居テナント CRUD を追加`

---

## Task 11: Excel 取込（ビル＋調査 / テナント明細）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingImportTest.php`
- Create: `app/Http/Controllers/Tenant/AreaBuildingImportController.php`
- Create: `resources/views/tenant/area-buildings/import.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`（「Excel 取込」ボタン）

方式は DAD 工事案件・仕入れ案件と同じ **SheetJS（クライアント側で解析・プレビュー）→ サーバで確定**。
確定はふつうの `<form>` POST で、正規化済みの行を hidden の JSON として送る。

⚠ **`fetch` は使わない。** GET の `fetch` にヘッダーを付け忘れる Bug #35 に触れないうえ、
`AjaxErrorFeedbackTest::test_every_fetch_view_is_classified` の分類対象にもならない。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingImportTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingImportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    private function importBuildings(array $rows, string $month = '2026-08')
    {
        return $this->actingAs($this->manager())->post('/tenant/area-buildings/import', [
            'kind'           => 'buildings',
            'surveyed_month' => $month,
            'rows'           => json_encode($rows),
        ]);
    }

    private function importTenants(array $rows)
    {
        return $this->actingAs($this->manager())->post('/tenant/area-buildings/import', [
            'kind' => 'tenants',
            'rows' => json_encode($rows),
        ]);
    }

    public function test_staff_cannot_reach_the_import_screen(): void
    {
        $this->actingAs($this->staff())->get('/tenant/area-buildings/import')->assertForbidden();
        $this->actingAs($this->staff())->post('/tenant/area-buildings/import', [])->assertForbidden();
    }

    /** ⚠ /import が {building} の ID として解釈されないこと */
    public function test_import_route_is_not_swallowed_by_the_show_route(): void
    {
        $this->actingAs($this->manager())
            ->get('/tenant/area-buildings/import')
            ->assertOk()
            ->assertViewIs('tenant.area-buildings.import');
    }

    // ============================================================
    // ビル＋調査（設計 §7.1）
    // ============================================================

    public function test_creates_new_buildings_with_a_survey(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'address' => '松山市1-1', 'total_floors' => '5', 'operating' => '4', 'vacant' => '1', 'unknown' => '0'],
            ['name' => 'ベータビル',   'address' => '松山市2-2', 'total_floors' => '3', 'operating' => '3', 'vacant' => '0', 'unknown' => '0'],
        ])->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSame(2, AreaBuilding::count());
        $this->assertSame(2, AreaBuildingSurvey::count());

        $alpha = AreaBuilding::where('name', 'アルファビル')->firstOrFail();
        $this->assertSame('松山市1-1', $alpha->address);
        $this->assertSame(5, $alpha->total_floors);

        $survey = $alpha->surveys()->firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame([4, 1, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
        $this->assertNotNull($survey->surveyed_by, '取込を実行したユーザーが調査者に入っていない');
    }

    /** 既存ビルにはビル名で突合して調査回だけ足す */
    public function test_matches_an_existing_building_by_name(): void
    {
        $existing = $this->makeBuilding('アルファビル', ['address' => '既存の住所', 'total_floors' => 9]);

        $this->importBuildings([
            ['name' => ' アルファビル ', 'address' => 'Excel の住所', 'total_floors' => '5', 'operating' => '4', 'vacant' => '1'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count(), '同じビルが二重に作られている');

        $existing->refresh();
        $this->assertSame('既存の住所', $existing->address, '既存の値が上書きされている');
        $this->assertSame(9, $existing->total_floors, '既存の値が上書きされている');
        $this->assertSame(1, $existing->surveys()->count());
    }

    /** 空の項目だけ Excel の値で補完する（設計 §7.1） */
    public function test_fills_only_the_blank_fields_of_an_existing_building(): void
    {
        $existing = $this->makeBuilding('アルファビル');   // address / total_floors とも null

        $this->importBuildings([
            ['name' => 'アルファビル', 'address' => 'Excel の住所', 'total_floors' => '5', 'operating' => '1'],
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('Excel の住所', $existing->address);
        $this->assertSame(5, $existing->total_floors);
    }

    /** 同じビル・同じ調査年月は取り込まずスキップし、件数を報告する（設計 §7.1） */
    public function test_skips_a_survey_that_already_exists_for_the_same_month(): void
    {
        $existing = $this->makeBuilding('アルファビル');
        $this->makeSurvey($existing, '2026-08-01', 9, 1);

        $response = $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '4', 'vacant' => '1'],
        ]);

        $this->assertSame(1, $existing->surveys()->count());
        $this->assertSame(9, $existing->surveys()->first()->operating_count, '既存の調査が上書きされている');
        $this->assertStringContainsString('スキップ 1 件', session('success'));
    }

    /** Excel 側に年月列があればそちらを優先する（設計 §7.1） */
    public function test_row_level_month_wins_over_the_screen_default(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '1', 'surveyed_month' => '2025年6月'],
            ['name' => 'ベータビル',   'operating' => '1'],
        ], '2026-08')->assertRedirect();

        $this->assertSame(
            '2025-06-01',
            AreaBuilding::where('name', 'アルファビル')->firstOrFail()->surveys()->first()->surveyed_month->format('Y-m-d')
        );
        $this->assertSame(
            '2026-08-01',
            AreaBuilding::where('name', 'ベータビル')->firstOrFail()->surveys()->first()->surveyed_month->format('Y-m-d')
        );
    }

    /** 全角数字・カンマ・空白を落としてから数値判定する（設計 §7.3） */
    public function test_normalizes_full_width_digits_and_separators(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '１，２３４', 'vacant' => ' 5 ', 'unknown' => '', 'total_floors' => '１０'],
        ])->assertRedirect();

        $building = AreaBuilding::where('name', 'アルファビル')->firstOrFail();
        $this->assertSame(10, $building->total_floors);

        $survey = $building->surveys()->firstOrFail();
        $this->assertSame([1234, 5, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    /** 数値にならない値は行ごと取り込まず警告に出す（設計 §7.3） */
    public function test_rows_with_non_numeric_counts_are_skipped_and_reported(): void
    {
        $response = $this->importBuildings([
            ['name' => '正常ビル', 'operating' => '3'],
            ['name' => '数値NGビル', 'operating' => '数棟'],
            ['name' => '',        'operating' => '1'],
        ]);

        $this->assertSame(['正常ビル'], AreaBuilding::pluck('name')->all());
        $this->assertStringContainsString('数値不正でスキップ 2 件', session('success'));
    }

    public function test_month_is_required_for_the_buildings_kind(): void
    {
        $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/import')
            ->post('/tenant/area-buildings/import', [
                'kind' => 'buildings',
                'rows' => json_encode([['name' => 'X', 'operating' => '1']]),
            ])
            ->assertSessionHasErrors('surveyed_month');

        $this->assertSame(0, AreaBuilding::count());
    }

    // ============================================================
    // テナント明細（設計 §7.2）
    // ============================================================

    public function test_imports_tenant_rows_into_an_existing_building(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'industry' => '飲食', 'status' => '営業中'],
            ['building_name' => 'アルファビル', 'floor' => '-1', 'room_number' => 'B101', 'name' => '', 'industry' => '', 'status' => '空室'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '', 'industry' => '', 'status' => ''],
        ])->assertRedirect();

        $this->assertSame(3, $building->tenants()->count());

        $statuses = $building->tenants()->orderBy('id')->pluck('status')->all();
        $this->assertSame(
            [AreaTenantStatus::Operating, AreaTenantStatus::Vacant, AreaTenantStatus::Unknown],
            $statuses
        );
        $this->assertSame(-1, $building->tenants()->where('room_number', 'B101')->firstOrFail()->floor);
    }

    /** 台帳に無いビル名の行は取り込まず警告に出す。ビルの自動生成はしない（設計 §7.2） */
    public function test_tenant_rows_for_unknown_buildings_are_reported_not_created(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'name' => '入る', 'status' => '営業'],
            ['building_name' => '知らないビル', 'name' => '入らない', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count(), 'ビルが自動生成されている');
        $this->assertSame(1, AreaBuildingTenant::count());
        $this->assertSame('入る', $building->tenants()->firstOrFail()->name);
        $this->assertStringContainsString('知らないビル', session('success'));
    }

    // ============================================================
    // 入力の防御
    // ============================================================

    public function test_rejects_malformed_json(): void
    {
        $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/import')
            ->post('/tenant/area-buildings/import', [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => 'これはJSONではない',
            ])
            ->assertRedirect('/tenant/area-buildings/import');

        $this->assertSame('取り込む行がありません。', session('error'));
        $this->assertSame(0, AreaBuilding::count());
    }

    public function test_rejects_too_many_rows(): void
    {
        $rows = array_fill(0, 2001, ['name' => 'X', 'operating' => '1']);

        $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/import')
            ->post('/tenant/area-buildings/import', [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode($rows),
            ])
            ->assertRedirect('/tenant/area-buildings/import');

        $this->assertStringContainsString('2000 行までです', session('error'));
        $this->assertSame(0, AreaBuilding::count());
    }

    /** SheetJS は SRI 付きで読み込む（新規に足す CDN スクリプトの方針。設計 §7） */
    public function test_sheetjs_is_loaded_from_jsdelivr_with_sri(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/import')->getContent();

        $this->assertStringContainsString('cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html, '本番でブロックされる CDN を使っている');
        $this->assertMatchesRegularExpression('/integrity="sha384-[A-Za-z0-9+\/=]+"/', $html, 'SRI が付いていない');
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
    }

    /** ⚠ 取込プレビューの option は静的生成（Bug #16） */
    public function test_mapping_selects_do_not_use_x_for(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/import')->getContent();

        $this->assertStringNotContainsString('<template x-for', $html);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingImportTest
```

Expected: FAIL — 404

- [ ] **Step 3: 取込コントローラを書く**

`app/Http/Controllers/Tenant/AreaBuildingImportController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビル調査の Excel 取込（設計 §7）。
 *
 * クライアント側の SheetJS がシート選択・列マッピング・プレビューまで行い、
 * 正規化済みの行を hidden の JSON として POST してくる。サーバ側でもう一度正規化する
 * （画面を経由しない POST でも壊れたデータが入らないようにするため）。
 */
class AreaBuildingImportController extends Controller
{
    /** 1 回の取込で受け付ける最大行数 */
    private const MAX_ROWS = 2000;

    public function form()
    {
        return view('tenant.area-buildings.import');
    }

    public function execute(Request $request)
    {
        $validated = $request->validate([
            'kind'           => 'required|in:buildings,tenants',
            'surveyed_month' => 'required_if:kind,buildings|nullable|date_format:Y-m',
            'rows'           => 'required|string',
        ], [], [
            // ⚠ 第3引数が attributes。グローバルの rows は「原価明細」なので上書きする
            'rows' => '取込データ',
        ]);

        $rows = json_decode($validated['rows'], true);

        if (! is_array($rows) || $rows === []) {
            return back()->with('error', '取り込む行がありません。');
        }

        if (count($rows) > self::MAX_ROWS) {
            return back()->with('error', '一度に取り込めるのは ' . self::MAX_ROWS . ' 行までです。ファイルを分割してください。');
        }

        $message = $validated['kind'] === 'buildings'
            ? $this->importBuildings($rows, $validated['surveyed_month'], (int) Auth::id())
            : $this->importTenants($rows);

        return redirect()->route('tenant.area-buildings.index')->with('success', $message);
    }

    // ============================================================
    // ビル＋調査
    // ============================================================

    private function importBuildings(array $rows, string $defaultMonth, int $userId): string
    {
        $created = 0;
        $added   = 0;
        $skipped = 0;
        $invalid = 0;

        $map = $this->buildingMapByNormalizedName();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $invalid++;
                continue;
            }

            $rawName = (string) ($row['name'] ?? '');
            $key     = AreaBuilding::normalizeName($rawName);
            if ($key === '') {
                $invalid++;
                continue;
            }

            $counts = [
                'operating_count' => $this->parseCount($row['operating'] ?? null),
                'vacant_count'    => $this->parseCount($row['vacant'] ?? null),
                'unknown_count'   => $this->parseCount($row['unknown'] ?? null),
            ];
            if (in_array(null, $counts, true)) {
                $invalid++;
                continue;
            }

            $month = $this->parseMonth($row['surveyed_month'] ?? null) ?? $defaultMonth;
            $floors = $this->parseInt($row['total_floors'] ?? null);
            $address = $this->nullableString($row['address'] ?? null, 255);

            if (isset($map[$key])) {
                $building = AreaBuilding::find($map[$key]);
            } else {
                $building = AreaBuilding::create([
                    'name'         => mb_substr(trim($rawName), 0, 255),
                    'address'      => $address,
                    'total_floors' => $floors === false ? null : $floors,
                    'created_by'   => $userId,
                ]);
                $map[$key] = $building->id;
                $created++;
            }

            if ($building === null) {
                $invalid++;
                continue;
            }

            // 既存ビルは「空の項目だけ」Excel の値で補完する（既存の値は上書きしない）
            $fill = [];
            if (blank($building->address) && filled($address)) {
                $fill['address'] = $address;
            }
            if ($building->total_floors === null && is_int($floors)) {
                $fill['total_floors'] = $floors;
            }
            if ($fill !== []) {
                $building->update($fill);
            }

            // 同じビル・同じ調査年月が既にあれば取り込まずスキップする
            // ⚠ whereDate で見る（= 比較は MySQL と SQLite で割れうる。Task 9 の注記を参照）
            if ($building->surveys()->whereDate('surveyed_month', $month . '-01')->exists()) {
                $skipped++;
                continue;
            }

            try {
                AreaBuildingSurvey::create(array_merge($counts, [
                    'area_building_id' => $building->id,
                    'surveyed_month'   => $month . '-01',
                    'surveyed_by'      => $userId,
                ]));
                $added++;
            } catch (QueryException) {
                // UNIQUE のバックストップ（同じファイル内に同一ビル・同一月が 2 行あった場合）
                $skipped++;
            }
        }

        return sprintf(
            '取込が完了しました。ビル新規 %d 件 / 調査追加 %d 件 / 同一年月のためスキップ %d 件 / 数値不正でスキップ %d 件',
            $created, $added, $skipped, $invalid
        );
    }

    // ============================================================
    // テナント明細
    // ============================================================

    private function importTenants(array $rows): string
    {
        $created   = 0;
        $invalid   = 0;
        $unmatched = [];

        $map = $this->buildingMapByNormalizedName();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $invalid++;
                continue;
            }

            $rawBuilding = (string) ($row['building_name'] ?? '');
            $key         = AreaBuilding::normalizeName($rawBuilding);
            if ($key === '') {
                $invalid++;
                continue;
            }

            // 台帳に無いビル名の行は取り込まない（ビルの自動生成はしない。設計 §7.2）
            if (! isset($map[$key])) {
                $unmatched[$key] = true;
                continue;
            }

            $floor = $this->parseInt($row['floor'] ?? null);

            AreaBuildingTenant::create([
                'area_building_id' => $map[$key],
                'floor'            => is_int($floor) ? $floor : null,
                'room_number'      => $this->nullableString($row['room_number'] ?? null, 50),
                'name'             => $this->nullableString($row['name'] ?? null, 255),
                'industry'         => $this->nullableString($row['industry'] ?? null, 100),
                'status'           => AreaTenantStatus::fromRawLabel($row['status'] ?? null)->value,
            ]);
            $created++;
        }

        $message = sprintf('取込が完了しました。テナント %d 件を登録しました。', $created);

        if ($invalid > 0) {
            $message .= sprintf(' ビル名が空の行 %d 件をスキップしました。', $invalid);
        }
        if ($unmatched !== []) {
            $names = array_keys($unmatched);
            $shown = array_slice($names, 0, 10);
            $message .= ' 台帳に無いビル名のためスキップ: ' . implode(' / ', $shown);
            if (count($names) > count($shown)) {
                $message .= sprintf(' ほか %d 件', count($names) - count($shown));
            }
        }

        return $message;
    }

    // ============================================================
    // 正規化ヘルパー
    // ============================================================

    /**
     * 正規化したビル名 → id。
     *
     * ⚠ 同じキーに複数のビルがぶら下がる場合は id の小さいほうを採る（後勝ちにしない）。
     * ⚠ 台帳が数千棟になったら SQL 側の突合へ移す。現状の想定は数十〜数百棟。
     *
     * @return array<string, int>
     */
    private function buildingMapByNormalizedName(): array
    {
        $map = [];

        foreach (AreaBuilding::orderBy('id')->get(['id', 'name']) as $building) {
            $key = AreaBuilding::normalizeName($building->name);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $building->id;
            }
        }

        return $map;
    }

    /**
     * 件数欄。空欄は 0、数値にならない値は null（＝その行を取り込まない）。
     */
    private function parseCount(mixed $raw): ?int
    {
        $value = $this->parseInt($raw);

        if ($value === null) {
            return 0;       // 空欄は 0
        }
        if ($value === false || $value < 0) {
            return null;    // 数値にならない / 負数
        }

        return $value;
    }

    /**
     * 全角数字・カンマ・空白・「円」「¥」を落としてから整数として読む。
     *
     * @return int|null|false null = 空欄 / false = 数値として解釈できない
     */
    private function parseInt(mixed $raw): int|null|false
    {
        if ($raw === null) {
            return null;
        }

        // ⚠ mb_convert_kana は必須。/u 付きの \d は全角数字にも一致するが、
        //   (int) '１２３' は 0 になるので、判定の前に半角へ寄せる必要がある。
        $s = mb_convert_kana(trim((string) $raw), 'n');                 // 全角数字 → 半角
        // ⚠ \x{3000} は冗長（/u が PCRE2_UCP を立てるので \s が U+3000 に当たる）。
        //   UCP 無効なビルドへの保険として残している。
        $s = preg_replace('/[,，\s\x{3000}円¥￥]/u', '', $s);

        if ($s === '') {
            return null;
        }

        return preg_match('/\A-?\d+\z/', $s) === 1 ? (int) $s : false;
    }

    /**
     * 「2026年8月」「2026/08」「2026-08-15」などを 'Y-m' に正規化する。
     */
    private function parseMonth(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $s = mb_convert_kana(trim((string) $raw), 'n');
        $s = str_replace(['年', '/', '.'], ['-', '-', '-'], $s);
        $s = rtrim($s, '月');

        if (preg_match('/\A(\d{4})-(\d{1,2})(?:-\d{1,2})?\z/', $s, $m) !== 1) {
            return null;
        }

        $month = (int) $m[2];

        return ($month >= 1 && $month <= 12) ? sprintf('%04d-%02d', (int) $m[1], $month) : null;
    }

    private function nullableString(mixed $raw, int $max): ?string
    {
        $s = trim((string) ($raw ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
```

- [ ] **Step 4: ルートを足す**

Task 8 で置いた「⚠ /area-buildings/import /geocode はこの行より上に置くこと」の**直前**に:

```php
        // Excel 取込（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/area-buildings/import', [\App\Http\Controllers\Tenant\AreaBuildingImportController::class, 'form'])
                ->name('tenant.area-buildings.import');
            Route::post('/area-buildings/import', [\App\Http\Controllers\Tenant\AreaBuildingImportController::class, 'execute'])
                ->name('tenant.area-buildings.import.execute');
        });
```

- [ ] **Step 5: SheetJS の SRI ハッシュを実測する**

**推測で書かない。** 実ファイルから計算する:

```bash
curl -sL https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js | openssl dgst -sha384 -binary | openssl base64 -A
```

出力を `sha384-` に続けて次の Step の `integrity` に貼る。

- [ ] **Step 6: 取込画面を書く**

`resources/views/tenant/area-buildings/import.blade.php`。
⚠ `x-data` 属性の中に `@json` を書かない。マッピング定義は `<script>` 内の定数に置き、
属性は `x-data="areaImportForm()"` だけにする（Bug #23 / Top trap #4）。

```blade
@extends('layouts.app')

@section('title', '周辺ビル調査 Excel 取込')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">Excel 取込</span>
@endsection

@section('content')
<div x-data="areaImportForm()">

    <a href="{{ route('tenant.area-buildings.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        周辺ビル調査に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">周辺ビル調査 Excel 取込</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- 取込の種類 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">取込の種類</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">種別</label>
                {{-- ⚠ option は @@foreach 相当の静的生成。x-for は使わない（Bug #16） --}}
                <select x-model="kind" @change="resetAll()"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="buildings">ビル＋調査</option>
                    <option value="tenants">テナント明細</option>
                </select>
            </div>
            <div x-show="kind === 'buildings'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月（全行に適用）</label>
                <input type="month" x-model="surveyedMonth"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="mt-1 text-xs text-gray-500">Excel 側に年月列があれば、そちらが優先されます。</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500" x-show="kind === 'tenants'">
            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。
        </p>
    </div>

    {{-- STEP 1: ファイル選択 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 1">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">1. ファイル選択</div>
        <div @dragover.prevent @drop.prevent="onDrop($event)"
             style="border: 2px dashed #6ee7b7; border-radius: 8px; padding: 28px; text-align: center; background: #f8fafc;">
            <div style="font-size: 14px; color: #374151; margin-bottom: 8px;">Excel ファイル（.xlsx / .xls / .csv）をここにドロップ</div>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">または</div>
            <label style="display: inline-block; padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer;">
                ファイルを選択
                <input type="file" accept=".xlsx,.xls,.csv" @change="onFile($event)" style="display:none;">
            </label>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">列の並びは自由です。次のステップで対応を指定できます。</div>
        </div>
    </div>

    {{-- STEP 2: 列マッピング --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 2">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">2. 列マッピング</div>
        <p class="text-xs text-gray-600 mb-3"><strong x-text="fileName"></strong> を読み込みました。</p>

        <div x-show="sheets.length > 1" class="mb-3">
            <label class="block text-sm font-semibold text-gray-700 mb-1">シート</label>
            {{-- option は JS から動的注入する（x-for で <option> を作らない。Bug #16） --}}
            <select id="area-import-sheet" @change="selectedSheet = $event.target.value; loadSheet();"
                    class="form-input w-full sm:w-72 h-[40px] px-3 border border-gray-300 rounded-md text-sm"></select>
        </div>

        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="min-width:560px;">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">列</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">見出し</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">サンプル</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">対応する項目</th>
                        </tr>
                    </thead>
                    <tbody id="area-import-mapping-body"></tbody>
                </table>
            </div>
        </div>

        <div class="flex gap-2 mt-4">
            <button type="button" @click="goToPreview()"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors">プレビューへ</button>
            <button type="button" @click="resetAll()"
                    class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors">やり直す</button>
        </div>
    </div>

    {{-- STEP 3: プレビュー --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">3. プレビュー</div>

        <p class="text-sm text-gray-700 mb-2">
            取込対象 <strong x-text="okRows().length"></strong> 行
            <span x-show="warnRows().length > 0" class="text-amber-700">
                / 警告 <strong x-text="warnRows().length"></strong> 行（取り込みません）
            </span>
        </p>

        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="min-width:640px;">
                    <thead><tr id="area-import-preview-head"></tr></thead>
                    <tbody id="area-import-preview-body"></tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        <form method="POST" action="{{ route('tenant.area-buildings.import.execute') }}" class="mt-4">
            @csrf
            <input type="hidden" name="kind" :value="kind">
            <input type="hidden" name="surveyed_month" :value="kind === 'buildings' ? surveyedMonth : ''">
            <input type="hidden" name="rows" :value="payload()">
            <div class="flex gap-2">
                <button type="submit" :disabled="okRows().length === 0"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors disabled:opacity-50">
                    この内容で取り込む
                </button>
                <button type="button" @click="step = 2"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors">戻る</button>
            </div>
        </form>
    </div>

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
        integrity="sha384-PASTE_MEASURED_HASH_HERE" crossorigin="anonymous"></script>
<script>
// 取込先の項目定義。x-data 属性へ渡さず、ここに置いて areaImportForm() から参照する（Bug #23）
var AREA_IMPORT_TARGETS = {
    buildings: [
        { key: 'name',           label: 'ビル名',   guess: /(ビル|建物|物件|名称|名前)/ },
        { key: 'address',        label: '所在地',   guess: /(所在|住所|場所)/ },
        { key: 'total_floors',   label: '階数',     guess: /(階数|総階)/ },
        { key: 'operating',      label: '営業',     guess: /(営業|入居|稼働)/ },
        { key: 'vacant',         label: '空き',     guess: /(空き|空室|空店)/ },
        { key: 'unknown',        label: '不明',     guess: /(不明|不詳)/ },
        { key: 'surveyed_month', label: '調査年月', guess: /(年月|調査月|調査日)/ }
    ],
    tenants: [
        { key: 'building_name', label: 'ビル名',     guess: /(ビル|建物|物件)/ },
        { key: 'floor',         label: '階',         guess: /(階)/ },
        { key: 'room_number',   label: '部屋番号',   guess: /(部屋|号室|区画|室番)/ },
        { key: 'name',          label: 'テナント名', guess: /(テナント|店舗|会社|名称)/ },
        { key: 'industry',      label: '業種',       guess: /(業種|業態|カテゴリ)/ },
        { key: 'status',        label: '状態',       guess: /(状態|ステータス|区分)/ }
    ]
};

function areaImportNormalizeNumber(raw) {
    return String(raw === undefined || raw === null ? '' : raw)
        .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
        .replace(/[,，\s　円¥￥]/g, '');
}

function areaImportForm() {
    return {
        kind: 'buildings',
        step: 1,
        fileName: '',
        sheets: [],
        selectedSheet: '',
        surveyedMonth: '',
        allRows: [],
        headerRowIndex: 0,
        columns: [],
        previewRows: [],
        _workbook: null,

        targets: function () { return AREA_IMPORT_TARGETS[this.kind]; },

        resetAll: function () {
            this.step = 1;
            this.fileName = '';
            this.sheets = [];
            this.selectedSheet = '';
            this.allRows = [];
            this.headerRowIndex = 0;
            this.columns = [];
            this.previewRows = [];
            this._workbook = null;
        },

        onFile: async function (e) {
            var file = e.target.files && e.target.files[0];
            if (file) { await this.readExcel(file); }
        },

        onDrop: async function (e) {
            var file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) { await this.readExcel(file); }
        },

        readExcel: async function (file) {
            if (typeof XLSX === 'undefined') {
                alert('Excel 読み込みライブラリが読み込まれていません。ページを再読み込みしてください。');
                return;
            }
            this.fileName = file.name;
            try {
                var buf = await file.arrayBuffer();
                var wb = XLSX.read(buf, { type: 'array' });
                this._workbook = wb;
                this.sheets = wb.SheetNames;
                this.selectedSheet = wb.SheetNames[0];
                this.loadSheet();
                this.step = 2;
                if (wb.SheetNames.length > 1) { this.injectSheetOptions(); }
            } catch (err) {
                alert('ファイルの読み込みに失敗しました: ' + err.message);
            }
        },

        injectSheetOptions: function () {
            var self = this;
            setTimeout(function () {
                var sel = document.getElementById('area-import-sheet');
                if (!sel) { return; }
                sel.innerHTML = '';
                self.sheets.forEach(function (name) {
                    var opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    if (name === self.selectedSheet) { opt.selected = true; }
                    sel.appendChild(opt);
                });
            }, 50);
        },

        loadSheet: function () {
            if (!this._workbook) { return; }
            var ws = this._workbook.Sheets[this.selectedSheet];
            this.allRows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            var first = this.allRows.findIndex(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });
            this.headerRowIndex = first >= 0 ? first : 0;
            this.buildColumns();
            this.renderMapping();
        },

        buildColumns: function () {
            var header = this.allRows[this.headerRowIndex] || [];
            var body = this.allRows.slice(this.headerRowIndex + 1, this.headerRowIndex + 4);
            var colCount = this.allRows.length
                ? Math.max.apply(null, this.allRows.map(function (r) { return r.length; }))
                : 0;
            var targets = this.targets();
            var used = {};
            var cols = [];

            for (var i = 0; i < colCount; i++) {
                var headerText = String(header[i] || '').replace(/\s/g, '');
                var mapping = '';
                for (var t = 0; t < targets.length; t++) {
                    if (!used[targets[t].key] && targets[t].guess.test(headerText)) {
                        mapping = targets[t].key;
                        used[mapping] = true;
                        break;
                    }
                }
                cols.push({
                    idx: i,
                    letter: XLSX.utils.encode_col(i),
                    header: String(header[i] || ''),
                    samples: body.map(function (r) { return String(r[i] === undefined ? '' : r[i]); }),
                    mapping: mapping
                });
            }
            this.columns = cols;
        },

        renderMapping: function () {
            var self = this;
            var body = document.getElementById('area-import-mapping-body');
            if (!body) { return; }
            body.innerHTML = '';

            this.columns.forEach(function (col) {
                var tr = document.createElement('tr');

                [col.letter, col.header || '（空）', col.samples.filter(Boolean).join(' / ')].forEach(function (text) {
                    var td = document.createElement('td');
                    td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-gray-700';
                    td.textContent = text;
                    tr.appendChild(td);
                });

                var td = document.createElement('td');
                td.className = 'px-3 py-2 border-b border-gray-200';
                var sel = document.createElement('select');
                sel.className = 'h-8 px-2 border border-gray-300 rounded text-xs bg-white';
                // option は DOM API で静的に作る（x-for で <option> を生成しない。Bug #16）
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '— 使わない —';
                sel.appendChild(blank);
                self.targets().forEach(function (target) {
                    var opt = document.createElement('option');
                    opt.value = target.key;
                    opt.textContent = target.label;
                    if (target.key === col.mapping) { opt.selected = true; }
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', function (e) { col.mapping = e.target.value; });
                td.appendChild(sel);
                tr.appendChild(td);

                body.appendChild(tr);
            });
        },

        goToPreview: function () {
            var map = {};
            this.columns.forEach(function (c) { if (c.mapping) { map[c.mapping] = c.idx; } });

            var body = this.allRows.slice(this.headerRowIndex + 1).filter(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });

            var isBuildings = this.kind === 'buildings';
            var cell = function (row, key) {
                return map[key] === undefined ? '' : String(row[map[key]] === undefined ? '' : row[map[key]]).trim();
            };

            this.previewRows = body.map(function (r) {
                var out = {};
                var warnings = [];

                if (isBuildings) {
                    out.name = cell(r, 'name');
                    out.address = cell(r, 'address');
                    out.total_floors = cell(r, 'total_floors');
                    out.operating = cell(r, 'operating');
                    out.vacant = cell(r, 'vacant');
                    out.unknown = cell(r, 'unknown');
                    out.surveyed_month = cell(r, 'surveyed_month');

                    if (out.name === '') { warnings.push('ビル名が空'); }
                    ['operating', 'vacant', 'unknown'].forEach(function (key) {
                        var v = areaImportNormalizeNumber(out[key]);
                        if (v !== '' && !/^\d+$/.test(v)) { warnings.push(key + ' が数値でない'); }
                    });
                } else {
                    out.building_name = cell(r, 'building_name');
                    out.floor = cell(r, 'floor');
                    out.room_number = cell(r, 'room_number');
                    out.name = cell(r, 'name');
                    out.industry = cell(r, 'industry');
                    out.status = cell(r, 'status');

                    if (out.building_name === '') { warnings.push('ビル名が空'); }
                }

                out._warnings = warnings;
                return out;
            });

            this.step = 3;
            this.renderPreview();
        },

        renderPreview: function () {
            var head = document.getElementById('area-import-preview-head');
            var body = document.getElementById('area-import-preview-body');
            if (!head || !body) { return; }

            var targets = this.targets();
            head.innerHTML = '';
            targets.concat([{ key: '_warnings', label: '警告' }]).forEach(function (t) {
                var th = document.createElement('th');
                th.className = 'px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200';
                th.textContent = t.label;
                head.appendChild(th);
            });

            body.innerHTML = '';
            this.previewRows.slice(0, 100).forEach(function (row) {
                var tr = document.createElement('tr');
                if (row._warnings.length > 0) { tr.style.background = '#fffbeb'; }
                targets.forEach(function (t) {
                    var td = document.createElement('td');
                    td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-gray-700';
                    td.textContent = row[t.key] || '';
                    tr.appendChild(td);
                });
                var td = document.createElement('td');
                td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-amber-700';
                td.textContent = row._warnings.join(' / ');
                tr.appendChild(td);
                body.appendChild(tr);
            });
        },

        okRows: function () {
            return this.previewRows.filter(function (r) { return r._warnings.length === 0; });
        },

        warnRows: function () {
            return this.previewRows.filter(function (r) { return r._warnings.length > 0; });
        },

        payload: function () {
            return JSON.stringify(this.okRows().map(function (r) {
                var copy = Object.assign({}, r);
                delete copy._warnings;
                return copy;
            }));
        }
    };
}
</script>
@endpush
```

⚠ `@push('scripts')` は `layouts/app.blade.php:164` の `@stack('scripts')` で展開される
（無いと静かに捨てられる。Bug #28。2026-07-26 に追加済みで実在することを確認済み）。

- [ ] **Step 7: 一覧に取込ボタンを足す**

`index.blade.php` の「新規登録」ボタンの**直前**（同じ `@if` ブロック内）:

```blade
            <a href="{{ route('tenant.area-buildings.import') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 border border-gray-300 bg-white text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors w-full sm:w-auto">
                Excel 取込
            </a>
```

ボタンが 2 つ以上になるので、`<h1>` の隣は `<div class="flex flex-col sm:flex-row gap-2">` でくくる。

- [ ] **Step 8: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingImportTest
```

Expected: PASS（15 tests）

- [ ] **Step 9: 変異テストで 5 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `importBuildings()` の「同一年月スキップ」判定を削除 | `test_skips_a_survey_that_already_exists_for_the_same_month` が赤 |
| 2 | 既存ビルの補完で `blank($building->address) &&` を削除（常に上書き） | `test_matches_an_existing_building_by_name` が赤 |
| 3 | `parseCount()` の `$value === false` を `false` 固定に（不正値を 0 扱い） | `test_rows_with_non_numeric_counts_are_skipped_and_reported` が赤 |
| 4 | `importTenants()` の `! isset($map[$key])` の分岐を `AreaBuilding::create(...)` に | `test_tenant_rows_for_unknown_buildings_are_reported_not_created` が赤 |
| 5 | `parseMonth()` の戻り値を常に `null` に（画面の既定月だけを使う） | `test_row_level_month_wins_over_the_screen_default` が赤 |

- [ ] **Step 10: ブラウザで実際に取り込めることを確認する**

⚠ サーバ側テストは JSON を PHP から手で送るので、**SheetJS の解析・マッピング・
プレビューが壊れていても緑のまま通る**（Bug #28 / #35 と同じ構図）。
ローカルの実ブラウザで実データの Excel を 1 本流すこと。

```bash
APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan serve --port=8000
```

確認する項目:
- [ ] シートが複数ある Excel でシート選択が出て、切り替えると列が変わる
- [ ] 見出しからの自動推測が当たっている（外れていても手で直せる）
- [ ] 数値不正の行が黄色く出て、取込対象から外れている
- [ ] 「この内容で取り込む」で一覧へ戻り、件数の内訳がメッセージに出る
- [ ] ブラウザのコンソールにエラーが 0 件

- [ ] **Step 10b: 状態エイリアスの取りこぼしを実データで測る**

2026-08-14 の Task 3 レビューで、`AreaTenantStatus::fromRawLabel()` を網羅的に叩いた結果、
**実データにありそうなのに `Unknown` へ落ちる語**が見つかっている:

| 入力 | 結果 |
|---|---|
| `募集中` / `テナント募集中` | Unknown（日本語としては「空き」を強く示唆する） |
| `退去済` | Unknown（同上） |
| `閉店` | Unknown（境界的） |
| `調査中` | Unknown（これは妥当） |

**設計書 §7.2 のエイリアス一覧に無い語なので、今は意図どおり `Unknown` に倒している。**
空室率は `空室数 = vacant_count + unknown_count`（設計 §4）で「不明」も空きに数えるため、
**この取りこぼしは空室率には影響しない**。影響するのはテナント明細のバッジが
「空き」（赤）でなく「不明」（灰）になる表示粒度だけ。`Operating` への誤爆は起きない。

- [ ] 実データを流した後、`area_building_tenants` の `status` 別件数を数える:
      `SELECT status, COUNT(*) FROM area_building_tenants GROUP BY status;`
- [ ] `unknown` が不自然に多ければ、元の Excel の生の値を見て
      `AreaTenantStatus::fromRawLabel()` のエイリアス一覧に `募集` / `退去` 系を追加する
- [ ] 追加したら Enum のテストにその語を足す
- [ ] **`operating` が不自然に多くないかも見る。** こちらのほうが危険で、
      空室率が下振れして経営指標が狂う（`unknown` 過多は空きに数えられるので率には影響しない）。
      疑わしければ `operating` の行の元データを引いて、営業と判定した根拠が妥当か確かめる:
      `SELECT b.name, t.room_number, t.name FROM area_building_tenants t JOIN area_buildings b ON b.id = t.area_building_id WHERE t.status = 'operating';`

⚠ **実データを見る前に語彙を先回りで広げないこと**（過剰適合になる）。

⚠ **2026-08-14 の Task 3 コード品質レビューで、`operating` 過多を生む欠陥が実際に見つかっている。**
`不稼働（休業中）` `入居者退去済み` `空床あり、他は稼働` `空室だが近日営業予定` がすべて
Operating に誤爆していた（部分一致のループが営業系を先に判定していたため）。
`fromRawLabel()` を順序非依存にして修正済み（両方の信号があれば Unknown / 否定語は営業判定を打ち消す）。
**この型の誤りは「Unknown 過多」だけを見ていても原理的に見つからない**ので、上の 2 方向を必ず両方測ること。

- [ ] **Step 11: コミット**

`/commit` で `feat(tenant): 周辺ビル調査の Excel 取込を追加`

---

## Task 12: 座標の一括取得

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingGeocodeTest.php`
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`（`index` に候補を渡す ＋ `storeCoordinates`）
- Modify: `routes/web.php`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`（ボタン＋フォーム＋JS）

⚠ **設計書 §5.1 のルート表にこのルートは無い**（§7.4 が要求している機能。プラン §1-1）。

⚠ **`fetch` は使わない。** ブラウザ側で Google の Geocoder を回し、結果をふつうの
`<form>` POST（hidden の JSON）でサーバへ渡す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingGeocodeTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingGeocodeTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    private function post(array $coordinates)
    {
        return $this->actingAs($this->manager())->post('/tenant/area-buildings/geocode', [
            'coordinates' => json_encode($coordinates),
        ]);
    }

    public function test_staff_cannot_post_coordinates(): void
    {
        $this->actingAs($this->staff())
            ->post('/tenant/area-buildings/geocode', ['coordinates' => '[]'])
            ->assertForbidden();
    }

    public function test_saves_coordinates_for_pending_buildings(): void
    {
        $building = $this->makeBuilding('未取得ビル', ['address' => '松山市1-1']);

        $this->post([
            ['id' => $building->id, 'latitude' => 33.83921234567, 'longitude' => 132.76571234567],
        ])->assertRedirect(route('tenant.area-buildings.index'));

        $building->refresh();
        $this->assertSame('33.8392123', $building->latitude, '小数第7位に丸めて保存されていない');
        $this->assertSame('132.7657123', $building->longitude);
        $this->assertStringContainsString('1 件', session('success'));
    }

    /**
     * ⚠ 既に座標がある行は上書きしない（手で直した位置を一括処理で潰さない）。
     *   二重課金の防止と対で load-bearing（設計 §7.4 / §11-11）。
     */
    public function test_does_not_overwrite_buildings_that_already_have_coordinates(): void
    {
        $building = $this->makeBuilding('取得済みビル', [
            'address' => '松山市1-1', 'latitude' => 33.8000000, 'longitude' => 132.7000000,
        ]);

        $this->post([
            ['id' => $building->id, 'latitude' => 34.0, 'longitude' => 133.0],
        ])->assertRedirect();

        $building->refresh();
        $this->assertSame('33.8000000', $building->latitude);
        $this->assertSame('132.7000000', $building->longitude);
    }

    public function test_ignores_out_of_range_and_malformed_entries(): void
    {
        $a = $this->makeBuilding('A', ['address' => '松山市1-1']);
        $b = $this->makeBuilding('B', ['address' => '松山市2-2']);
        $c = $this->makeBuilding('C', ['address' => '松山市3-3']);

        $this->post([
            ['id' => $a->id, 'latitude' => 91.0, 'longitude' => 132.0],       // 緯度が範囲外
            ['id' => $b->id, 'latitude' => 'あ', 'longitude' => 132.0],        // 数値でない
            ['id' => $c->id],                                                  // キー欠落
            'これは配列ですらない',
        ])->assertRedirect();

        $this->assertNull($a->fresh()->latitude);
        $this->assertNull($b->fresh()->latitude);
        $this->assertNull($c->fresh()->latitude);
    }

    public function test_rejects_malformed_json(): void
    {
        $this->actingAs($this->manager())
            ->from('/tenant/area-buildings')
            ->post('/tenant/area-buildings/geocode', ['coordinates' => 'ぐちゃぐちゃ'])
            ->assertRedirect('/tenant/area-buildings');

        $this->assertNotNull(session('error'));
    }

    // ============================================================
    // 一覧側の表示（費用の見え方）
    // ============================================================

    /** 座標未取得が 0 件なら Maps のスクリプト自体を出さない（設計 §6.0 / プラン §1-8） */
    public function test_list_does_not_load_maps_when_nothing_is_pending(): void
    {
        $this->makeBuilding('座標あり', ['address' => '松山市1-1', 'latitude' => 33.8, 'longitude' => 132.7]);
        $this->makeBuilding('住所なし');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings')->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html);
        $this->assertStringNotContainsString('一括取得', $html);
    }

    /** 未取得があるときだけボタンと Geocoder が出る。⚠ Map は 1 つも作らない */
    public function test_list_shows_the_button_with_the_pending_count(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);
        $this->makeBuilding('未取得B', ['address' => '松山市2-2']);

        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings');
        $html = $response->getContent();

        $this->assertSame(2, $response->viewData('pendingGeocodeCount'));
        $this->assertStringContainsString('座標未設定 2 件を一括取得', $html);
        $this->assertStringContainsString('maps.googleapis.com', $html);
        $this->assertStringContainsString('new google.maps.Geocoder()', $html);
        $this->assertStringNotContainsString('new google.maps.Map(', $html, '一覧で地図を生成している（課金する）');
    }

    /** staff にはボタンを出さない */
    public function test_staff_does_not_see_the_button(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();

        $this->assertStringNotContainsString('一括取得', $html);
        $this->assertStringNotContainsString('maps.googleapis.com', $html);
    }

    /** 1 回の実行で叩く上限（設計 §7.4）。超過分は次回に回し、残件数を知らせる */
    public function test_pending_list_is_capped_and_the_remainder_is_reported(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            $this->makeBuilding("未取得{$i}", ['address' => "松山市{$i}"]);
        }

        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings');

        $this->assertSame(205, $response->viewData('pendingGeocodeCount'));
        $this->assertCount(200, $response->viewData('pendingGeocode'), '1 回の上限が効いていない');
        $response->assertSee('座標未設定 205 件を一括取得');
        $response->assertSee('今回は 200 件まで');
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingGeocodeTest
```

Expected: FAIL — 404 / `Undefined view variable`

- [ ] **Step 3: コントローラを直す**

`AreaBuildingController` にクラス定数と `storeCoordinates()` を足し、`index()` を差し替える:

```php
    /**
     * 1 回の一括取得で Google に投げる上限（設計 §7.4）。
     * ⚠ 無制限にすると、取込ミスで大量の行が入ったときにそのままリクエストが飛ぶ。
     */
    public const GEOCODE_BATCH_LIMIT = 200;

    public function index(Request $request, AreaBuildingListService $service)
    {
        $canEdit = $request->user()->role->isManagerOrAbove();

        // 座標未取得の候補は、ボタンを出す人にだけ渡す（画面に住所を撒かない・無駄な検索もしない）
        $pendingCount = $canEdit ? AreaBuilding::pendingGeocodeCount() : 0;
        $pending      = $pendingCount > 0
            ? AreaBuilding::pendingGeocode(self::GEOCODE_BATCH_LIMIT)
                ->map(fn (AreaBuilding $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])
                ->values()
                ->all()
            : [];

        return view('tenant.area-buildings.index', [
            'rows'                => $service->paginate($request),
            'surveyYears'         => $service->surveyYears(),
            'vacancyOptions'      => AreaBuildingListService::VACANCY_OPTIONS,
            'pendingGeocode'      => $pending,
            'pendingGeocodeCount' => $pendingCount,
            'geocodeBatchLimit'   => self::GEOCODE_BATCH_LIMIT,
        ]);
    }

    /**
     * ブラウザで取得した座標をまとめて保存する（設計 §7.4）。
     *
     * ⚠ 既に座標がある行は更新しない。手で直した位置を一括処理で潰さないため。
     */
    public function storeCoordinates(Request $request)
    {
        $validated = $request->validate(
            ['coordinates' => 'required|string'],
            [],
            ['coordinates' => '取得した座標']
        );

        $decoded = json_decode($validated['coordinates'], true);

        if (! is_array($decoded)) {
            return redirect()->route('tenant.area-buildings.index')
                ->with('error', '座標データを解釈できませんでした。もう一度お試しください。');
        }

        $updated = 0;

        foreach (array_slice($decoded, 0, self::GEOCODE_BATCH_LIMIT) as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['latitude'], $item['longitude'])) {
                continue;
            }
            if (! is_numeric($item['latitude']) || ! is_numeric($item['longitude'])) {
                continue;
            }

            $lat = (float) $item['latitude'];
            $lng = (float) $item['longitude'];

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }

            $updated += AreaBuilding::whereKey($item['id'])
                ->whereNull('latitude')
                ->update([
                    'latitude'  => round($lat, 7),
                    'longitude' => round($lng, 7),
                ]);
        }

        $remaining = AreaBuilding::pendingGeocodeCount();

        return redirect()->route('tenant.area-buildings.index')->with(
            'success',
            $remaining > 0
                ? "{$updated} 件の座標を保存しました。座標未設定は残り {$remaining} 件です。"
                : "{$updated} 件の座標を保存しました。座標未設定はありません。"
        );
    }
```

- [ ] **Step 4: ルートを足す**

Task 11 で入れた取込ルートの group の中に 1 本追加する（`/{building}` より前）:

```php
            Route::post('/area-buildings/geocode', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'storeCoordinates'])
                ->name('tenant.area-buildings.geocode');
```

- [ ] **Step 5: 一覧にボタンとスクリプトを足す**

`index.blade.php` のフィルターバーの**直後**に:

```blade
    {{-- 座標の一括取得（経営層+管理者、未取得があるときだけ） --}}
    @if($pendingGeocodeCount > 0)
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <button type="button" id="btn-bulk-geocode" onclick="runBulkGeocode()" disabled
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md transition-colors disabled:opacity-50">
                    座標未設定 {{ $pendingGeocodeCount }} 件を一括取得
                </button>
                <span class="text-xs text-blue-900">
                    住所から座標を取得します。1 棟につき 1 回だけ問い合わせ、取得済みの棟は対象外です。
                    @if($pendingGeocodeCount > $geocodeBatchLimit)
                        <strong>今回は {{ $geocodeBatchLimit }} 件までで、残りは次回に回ります。</strong>
                    @endif
                </span>
                <span id="geocode-progress" class="text-xs font-semibold text-blue-900"></span>
            </div>
        </div>

        <form id="geocode-form" method="POST" action="{{ route('tenant.area-buildings.geocode') }}">
            @csrf
            <input type="hidden" name="coordinates" id="geocode-payload" value="">
        </form>
    @endif
```

ページの末尾（`@endsection` の直前）に:

```blade
@if($pendingGeocodeCount > 0)
    @push('scripts')
    <script>
    // 座標一括取得。⚠ 地図は生成しない（Geocoder だけ使う。設計 §6.0）
    var areaGeocoder = null;
    var AREA_PENDING = {{ \Illuminate\Support\Js::from($pendingGeocode) }};

    function onAreaGeocodeReady() {
        areaGeocoder = new google.maps.Geocoder();
        var btn = document.getElementById('btn-bulk-geocode');
        if (btn) { btn.disabled = false; }
    }

    function runBulkGeocode() {
        if (!areaGeocoder) {
            alert('Google Maps を読み込み中です。しばらくお待ちください。');
            return;
        }
        if (!confirm(AREA_PENDING.length + ' 件の住所から座標を取得します。よろしいですか？')) {
            return;
        }

        var btn = document.getElementById('btn-bulk-geocode');
        var progress = document.getElementById('geocode-progress');
        var results = [];
        var failed = 0;
        var i = 0;
        btn.disabled = true;

        function finish(note) {
            progress.textContent = note || ('取得 ' + results.length + ' 件 / 失敗 ' + failed + ' 件。保存しています…');
            document.getElementById('geocode-payload').value = JSON.stringify(results);
            document.getElementById('geocode-form').submit();
        }

        function step() {
            if (i >= AREA_PENDING.length) { finish(); return; }

            var item = AREA_PENDING[i];
            progress.textContent = '取得中… ' + (i + 1) + ' / ' + AREA_PENDING.length;

            // ⚠ 1 棟につきフル住所で 1 回だけ。段階フォールバック（最大 5 回）は使わない。
            //    失敗した棟は登録フォームから手で確定する（設計 §7.4）
            areaGeocoder.geocode({ address: item.address }, function (res, status) {
                if (status === 'OK' && res[0]) {
                    results.push({
                        id: item.id,
                        latitude: res[0].geometry.location.lat(),
                        longitude: res[0].geometry.location.lng()
                    });
                } else if (status === 'OVER_QUERY_LIMIT') {
                    finish('Google の呼び出し上限に達しました。取得できた ' + results.length + ' 件だけ保存します。');
                    return;
                } else {
                    failed++;
                }
                i++;
                setTimeout(step, 120);
            });
        }

        step();
    }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onAreaGeocodeReady&language=ja&region=JP" async defer></script>
    @endpush
@endif
```

⚠ `Js::from()` を使う（`@json` は構造区切りの `"` を素通しするので属性・スクリプトを壊す。Bug #23）。
⚠ `<script>` 内の `//` コメントにディレクティブ名を書かない（Bug #30）。上のコメントには含めていない。

- [ ] **Step 6: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter 'AreaBuildingGeocodeTest|AreaBuildingListTest'
```

Expected: PASS（Geocode 9 + List 12）

- [ ] **Step 7: 変異テストで 4 通り確認する**

| # | 変異 | 期待 |
|---|---|---|
| 1 | `storeCoordinates()` の `whereNull('latitude')` を削除 | `test_does_not_overwrite_buildings_that_already_have_coordinates` が赤 |
| 2 | `pendingGeocode(self::GEOCODE_BATCH_LIMIT)` を `pendingGeocode(100000)` に | `test_pending_list_is_capped_and_the_remainder_is_reported` が赤 |
| 3 | `index()` の `$pendingCount = $canEdit ? ... : 0;` を `AreaBuilding::pendingGeocodeCount()` 固定に | `test_staff_does_not_see_the_button` が赤 |
| 4 | ビューの `@if($pendingGeocodeCount > 0)`（スクリプト側）を外す | `test_list_does_not_load_maps_when_nothing_is_pending` が赤 |

- [ ] **Step 8: ブラウザで実際に動くことを確認する**

⚠ **サーバ側テストは JSON を手で送るだけなので、Geocoder のループが 1 度も走らなくても緑になる**
（Bug #28 / #35 と同じ構図）。ローカルで実際に押すこと。

- [ ] 住所つきのビルを 2〜3 件登録し、一覧のボタンを押す
- [ ] 進捗表示が進み、一覧に戻って「N 件の座標を保存しました」が出る
- [ ] もう一度一覧を開いてボタンが消えている（＝未取得が 0 件）
- [ ] Google Cloud Console の Geocoding API のリクエスト数が**棟数と同じだけ**増えている
      （段階フォールバックが混入していると棟数の数倍になる）

- [ ] **Step 9: コミット**

`/commit` で `feat(tenant): 周辺ビルの座標一括取得を追加`

---

## Task 13: 最終検証と本番反映

**Files:** 変更なし（検査と手順のみ）

- [ ] **Step 1: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: 既存テストと本機能の約 105 本がすべて PASS。
特に自動で新しいビューを拾う走査テストが緑であること:

- `MobileLayoutTest`（`<table>` が横スクロールできる祖先を持つ / `table-layout: fixed` に `min-width` がある）
- `AlpineXShowDisplayConflictTest`（`x-show` と `:style` の同居で `display` を書いていない）
- `AjaxErrorFeedbackTest`（本機能は `fetch` を 1 つも使わないので分類対象に増えない。**増えていたら設計から外れている**）
- `JapaneseValidationMessagesTest`（新しい `validate()` キーに和名がある）
- `LayoutScriptStackTest` / `LayoutStyleStackTest`

- [ ] **Step 2: `fetch` を増やしていないことを確認する**

```bash
grep -rn "fetch(" resources/views/tenant/area-buildings/
```

Expected: **0 件**。1 件でもあれば `AjaxErrorFeedbackTest` の 3 リストのどれかに分類し、
GET なら `X-Requested-With: XMLHttpRequest` を付ける（Bug #35 / #45）。

- [ ] **Step 3: 過去バグの横展開検査を回す**

```bash
# Bug #21: コンポーネント属性の &quot;
grep -rn "&quot;" resources/views/tenant/area-buildings/

# Bug #23: x-data 属性内の @json
grep -rn -A3 'x-data="' resources/views/tenant/area-buildings/ | grep '@json'

# Bug #26: @json に配列リテラル
grep -rn '@json(' resources/views/tenant/area-buildings/ | grep '\['

# Bug #30: JS コメント中のディレクティブ名（@@ でエスケープしていないもの）
grep -rnE '^[[:space:]]*//.*@(json|if|foreach|php|include|section|yield|stack|push)' resources/views/tenant/area-buildings/ | grep -v '@@'

# Bug #24: ->links()
grep -rn '\->links()' resources/views/tenant/area-buildings/

# Bug #22: キャスト済み enum への tryFrom()
grep -rn '::tryFrom(\$' app/Http/Controllers/Tenant/AreaBuilding*.php app/Models/AreaBuilding*.php

# Bug #17: Blade からの env() 直呼び
grep -rn "env(" resources/views/tenant/area-buildings/
```

Expected: **すべて 0 件**

- [ ] **Step 4: 本番同等にコンパイルして lint する（Bug #26 / #30）**

`view:cache` は成功表示してもコンパイル済み PHP を lint しない。生成物を必ず lint する:

```bash
APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan view:clear
```

Expected: `INVALID:` が 1 件も出ない

- [ ] **Step 5: ルートの並びを目で確認する**

```bash
APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan route:list --path=area-buildings
```

Expected: 20 本。`create` / `import` / `geocode` が `{building}` より**上**に並んでいること。

- [ ] **Step 6: サイドバーを 2 箇所とも直したか確認する**

```bash
grep -c "周辺ビル調査" resources/views/layouts/partials/sidebar.blade.php
```

Expected: `2`

- [ ] **Step 7: モバイル幅で崩れないことを実測する**

```bash
APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan serve --port=8000
```

ブラウザを 375px 幅にして一覧・詳細・各フォーム・取込画面を開き、コンソールで:

```js
(() => { const m = document.querySelector('main'); return { scrollWidth: m.scrollWidth, clientWidth: m.clientWidth, ok: m.scrollWidth === m.clientWidth }; })()
```

Expected: すべての画面で `ok: true`（表は `.scroll-hint-inner` の中だけが横スクロールする）。
⚠ 広い幅（1400px 程度）でも同じ測定をする。超過幅は定数になることがあり、片方だけでは判定できない（Bug #29）。

- [ ] **Step 8: コミットして main repo へ FF-merge する**

worktree に未コミットが無いことを確認してから、**main repo で**:

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only tenant-area-survey
```

- [ ] **Step 9: main repo の cwd で autoload を作り直す**

新規 PHP クラスを 9 本追加しているので必須。
⚠ **worktree から実行しない**（autoloader の `$baseDir` に worktree のパスが焼き込まれ、
main repo の Apache が worktree を参照する事故になる）。

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

- [ ] **Step 10: ローカル DB に raw SQL を流す**

⚠ **2026-08-14 訂正。** 当初ここは tinker + `DB::unprepared()` だけを案内していたが、
**この SQL ファイルは `CREATE TABLE` を 3 本含む**のに対し、`Connection::unprepared()` は
ファイル全体を **1 回の `PDO::exec()`** に渡す実装（`vendor/laravel/framework/.../Connection.php`）。
マルチステートメントが通るかは `PDO::MYSQL_ATTR_MULTI_STATEMENTS` の既定に依存し、
`config/database.php` にも `MySqlConnector` にも明示指定が無い。

実測（2026-08-14、Task 2 のコード品質レビュー）: `database/sql/` で tinker + `DB::unprepared()` を
案内しているファイルは**すべて単一ステートメント**。複数テーブルを含むファイル
（`create_mansion_tables.sql` 8 本 / `create_dad_tables.sql` 7 本 / `create_zeal_tables.sql` 5 本）は
**すべて `sudo mysql manage < file` を案内**しており、そちらが実証済みの慣行。

**第一の方法**（複数テーブルのファイルの既存慣行）:

```bash
cd /Users/masanori/site/manage && sudo mysql <db> < database/sql/2026-08-12-create-area-building-tables.sql
```

**代替**（`sudo mysql` が非対話でパスワードを渡せない場合）— ⚠ マルチステートメント依存:

```bash
cd /Users/masanori/site/manage && php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/2026-08-12-create-area-building-tables.sql'));"
```

⚠ ローカルの実 DB 名は `.env` を見ること（CLAUDE.md の記載と食い違っている可能性がある）。
⚠ `CREATE TABLE IF NOT EXISTS` なので**冪等**。途中で失敗しても再実行・個別実行で安全に復旧できる。
**だからこそ、下の確認を必ず 3 テーブル分すべて行うこと**（部分適用を見逃さないため）。

適用後の確認:

```bash
cd /Users/masanori/site/manage && php artisan db:table area_buildings && php artisan db:table area_building_surveys && php artisan db:table area_building_tenants
```

- [ ] **Step 11: 本番 DB への適用とデプロイ（要ユーザー明示承認）**

⚠ **ここから先はユーザーの明示承認が必要。** 承認が無いまま `./deploy.sh` を実行しない
（自動モードの分類器がブロックする。AskUserQuestion で可否を確認してから進める）。

順番:

1. 本番 DB に `database/sql/2026-08-12-create-area-building-tables.sql` を適用する
2. `./deploy.sh`（`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache`）

⚠ ルートを 19 本追加しているので **`git push` だけでは本番に反映されない**（`route:cache` の
再生成が要る。Bug #20 / #25 と同じ）。
⚠ `docs/` と `tests/` は rsync 除外なので本番には行かない（意図どおり）。

- [ ] **Step 12: 本番ブラウザで確認する（HTML では判定できない項目）**

以下は「HTML に出ているか」では判定できないので、**実際に触る**:

- [ ] 一覧のフィルタ「空室率: 全て」を選び、**2 ページ目**へ進んでもフィルタが維持される
- [ ] キーワードにテナント名を入れて検索できる（退去済みでは引っかからない）
- [ ] 詳細で乖離警告が出る（明細を 1 件だけ入れたビルで確認）
- [ ] 詳細の「Google マップで開く」が別タブで正しい位置を開く
- [ ] 登録フォームの「マップで確認」で地図が出て、ピンをドラッグすると緯度経度が更新される
- [ ] **Street View のボタンが地図に出ていない**（§6.0 の費用方針）
- [ ] Excel 取込を実データ 1 本で通す（シート選択・マッピング・警告・確定）
- [ ] 座標一括取得を押して、件数分だけ保存される
- [ ] **Ajax を叩いてから必須項目を空で送信**しても、生の JSON でなくフォームに戻る
      （本機能に `fetch` は無いので理屈上は起きないが、確認して記録に残す。Bug #35）
- [ ] バリデーションエラーの文言が日本語で、項目名が画面ラベルと一致している
      （「ビル名は必ず入力してください。」「所在地は…」）

- [ ] **Step 13: デプロイ後に Google Cloud Console を確認する**

- [ ] Geocoding API のリクエスト数が、一括取得を流した棟数と**同じだけ**増えている
      （数倍になっていたら段階フォールバックが混入している）
- [ ] Maps JavaScript API（Dynamic Maps）のカウントが、登録編集フォームで
      「マップで確認」を押した回数だけ増えている（一覧や詳細を開いた回数では増えない）

- [ ] **Step 14: ユーザーに API キーの保護を依頼する（設計 §6.4 / コード側では対処不能）**

⚠ **社内限定でもキーはブラウザに露出する**（`<script src="...key=...">` としてソースに出る）。
実装とは独立して、Google Cloud Console で次の設定をユーザーに依頼する:

- **HTTP リファラー制限** — `https://www.mitsuwat.co.jp/*` からの呼び出しだけ許可
- **API 制限** — このキーで使えるのを **Maps JavaScript API と Geocoding API だけ**に絞る（Places は有効にしない）
- **予算アラート** — 想定外の課金に早く気づくため

⚠ Google Maps の bootstrap スクリプトに SRI は付けられない（内容が動的生成されるためハッシュが固定されない）。
SRI を付けられるのはバージョン固定の SheetJS のような静的ファイルだけ。

- [ ] **Step 15: 後片付け**

- [ ] `origin/13.x` への push は**ユーザーの明示指示があったときだけ**
- [ ] マージ済みの worktree は `/clean_gone`（commit-commands）で掃除する
- [ ] `docs/BACKLOG.md` に「周辺ビル調査 第1段（本番稼働中）」を追記し、第2段の着手条件
      （実データを見てから色分け閾値と集計の粒度を決める）を残す

---

## Self-Review — 設計書との突き合わせ

このプランを書き終えた時点で、設計書の各節に対応するタスクがあることを確認した。

| 設計書 | 対応 |
|---|---|
| §3.1 `area_buildings` | Task 2（raw SQL + migration）/ Task 4（モデル） |
| §3.2 `area_building_surveys`（月初正規化 / `withTrashed`） | Task 2 / Task 4 |
| §3.3 `area_building_tenants` | Task 2 / Task 4 |
| §3.4 モデルと Enum（`badgeStyle` / casts / tryFrom 禁止） | Task 3 / Task 4 |
| §4 空室率の定義（不明は空き / 総数 0 は null / 整数演算 / 1 箇所集約） | Task 1 |
| §5.1 ルート（19 本 + `create`/`import` の順序） | Task 6 / 7 / 8 / 9 / 10 / 11 / 12 |
| §5.2 コントローラ 4 本 + `AreaBuildingListService` | Task 6 / 8 / 9 / 10 / 11 |
| §5.3 一覧（列 / 並び順 / フィルタ 3 種 / 20 件 / N+1 / null フィルタ / ページャ） | Task 6 |
| §5.4 詳細（ヘッダ / 位置リンク / 調査履歴 / テナント一覧 / 乖離警告） | Task 7 |
| §5.5 登録編集フォーム（地図 / 初回調査 / `value="0"` を入れない） | Task 8 |
| §5.6 調査・テナントの別画面（「保存して続けて登録」/ 静的 `<option>`） | Task 9 / Task 10 |
| §5.7 サイドバー | Task 6 |
| §6 / §6.0 / §6.1 地図（費用最小化 / `streetViewControl: false` / 押したときだけ生成） | Task 8 / Task 12 |
| §6.4 API キーの保護 | Task 13 Step 14（コードでは対処できないのでユーザー依頼） |
| §7.1 ビル＋調査の取込 | Task 11 |
| §7.2 テナント明細の取込 | Task 11 |
| §7.3 数値の正規化 | Task 11 |
| §7.4 座標の一括取得（1 棟 1 回 / 上限 200 / 未設定のみ） | Task 12 |
| §8 権限 | Task 8 / 9 / 10 / 11 / 12 の権限テスト |
| §10.1 日本語バリデーション | Task 5 |
| §11 テスト方針 1〜12 | 下表 |
| §13 本番反映 | Task 13 |

### 設計書 §11 のテスト一覧との対応

| # | 設計書のテスト | 実装先 |
|---|---|---|
| 1 | 空室率の計算 | Task 1 `VacancyRateTest`（⚠ float 除算の変異は原理的に検出できないので構造テストで補う。プラン §1-2） |
| 2 | `surveyed_month` の月初正規化 | Task 4 `AreaBuildingModelTest` |
| 3 | 一覧の「全て」フィルタ（HTTP レベル） | Task 6 `test_empty_vacancy_filter_means_all_over_real_http` |
| 4 | ページ送りでフィルタが維持される | Task 6 `test_pagination_keeps_the_keyword_filter`（⚠ `?? ''` 部分は区別できない。プラン §1-3） |
| 5 | 空データで 200 | Task 6 `test_empty_data_renders` / Task 7 `test_detail_renders_with_no_surveys_and_no_tenants` |
| 6 | 乖離警告 3 通り | Task 7 |
| 7 | Excel 取込（新規 / 既存追加 / 同一月スキップ / 数値不正） | Task 11 |
| 8 | テナント明細取込（台帳に無いビル名） | Task 11 |
| 9 | 権限（3 コントローラ） | Task 8 / 9 / 10（＋取込 Task 11・座標 Task 12） |
| 10 | ルート順序 | Task 8 `test_create_route_is_not_swallowed_by_the_show_route` / Task 11（`import`） |
| 11 | 座標一括取得が既存座標を叩かない | Task 4（モデル）/ Task 12（HTTP） |
| 12 | 詳細に埋め込み地図が無い | Task 7 `test_detail_does_not_load_the_maps_javascript_api` |

### 設計書に無く、このプランで足したテスト

- 調査回・テナントの**所有権チェック**（他ビルの子リソースへ URL を差し替えて到達できない）— Task 9 / 10
- 同一年月の**バリデーションエラー**（500 でなく差し戻し）— Task 9
- 調査者セレクトに**無効化済みユーザーが残る**（Bug #12）— Task 9
- `raw SQL` と `migration` の**列の一致**（drift 検出）— Task 2
- 一覧が**座標未取得 0 件のとき Maps を読み込まない**（費用方針）— Task 12
- SheetJS の **SRI**（設計 §7 の方針をテストで固定）— Task 11

### 未対応として残すもの（意図的）

- 一覧地図 / `properties` への緯度経度 / エリア集計と空室率推移 — **第2段**（設計 §9）
- ビル名の重複マージ UI — 取込でビル名が一致しなかった場合は別のビルとして登録される。
  第1段では手で編集・削除して整える（マージ機能は実データを見てから判断する）
- 一覧の全件メモリロード — 棟数が数千を超えたら SQL 側の並び替えへ移す（プラン §1-4）
