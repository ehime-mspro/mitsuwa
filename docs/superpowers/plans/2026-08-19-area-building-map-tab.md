# 周辺ビル調査 一覧地図タブと位置登録 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 周辺ビル調査の一覧に「地図」タブを足し、地図上をクリックして 187 棟の位置を順に登録できるようにする。あわせて所在地を画面から消し、空室率の帯を実データに合わせて 0 / 25 / 50 に統一する。

**Architecture:** 新しい画面は作らない。既存の一覧 `/tenant/area-buildings` に `?view=map` のクエリで表示を切り替え、地図の markup と JS は `_map.blade.php` に切り出す（index が肥大しないように、かつ表タブでは地図の JS が 1 行も出ないように）。閾値の判定は `App\Support\VacancyRate` の 1 箇所に集約し、一覧フィルタと地図の凡例が同じ定数を見る。座標の保存は 1 棟ぶんの新ルート（上書き可）で行い、住所ベースの一括取得（上書き不可）はそのまま温存する。

**Tech Stack:** Laravel 12 / Blade / 素の JavaScript（Alpine は使わない）/ Google Maps JavaScript API / PHPUnit（SQLite in-memory）

**設計書:** @docs/superpowers/specs/2026-08-19-area-building-map-tab-design.md
**作業場所:** `.claude/worktrees/tenant-area-survey`（branch `area-building-map-tab`）
**テスト実行:** `vendor/bin/phpunit`（worktree 内。main repo には `vendor/bin/phpunit` が無い）

---

## File Structure

**Create:**

| ファイル | 責務 |
|---|---|
| `resources/views/tenant/area-buildings/_map.blade.php` | 地図タブの markup（凡例 / 未登録件数 / 登録パネル / 地図の器）と、その JS 一式（`@push('scripts')`）・CSS（`@push('styles')`）。**`?view=map` のときだけ include される** |
| `tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php` | 1 棟ぶんの座標保存ルート（権限 / 上書き / 範囲 / 一括取得との独立性） |
| `tests/Feature/Tenant/AreaBuildingMapTabTest.php` | タブ切替 / 表タブに地図 JS が出ないこと / ピンデータ / 未登録件数 / 登録パネルの権限 |

**Modify:**

| ファイル | 変更 |
|---|---|
| `app/Support/VacancyRate.php` | `BAND_MID` / `BAND_HIGH` / `LEVEL_*` / `LEVELS` / `level()` を追加 |
| `app/Services/Tenant/AreaBuildingListService.php` | 定数を `VACANCY_OVER25` / `VACANCY_OVER50` へ。閾値は `VacancyRate::BAND_*` 経由。キーワード検索から `address` を外す。`paginateRows()` を切り出す |
| `app/Http/Controllers/Tenant/AreaBuildingController.php` | `index()` に `?view=map` 分岐とピン組み立て。`storeCoordinate()` を新設。`store()` / `update()` が送られてこない `address` を消さないようにする |
| `routes/web.php` | `POST /area-buildings/{building}/coordinates` を追加 |
| `resources/views/tenant/area-buildings/index.blade.php` | タブ / 所在地列の削除 / colgroup の調整 / `_map` の include |
| `resources/views/tenant/area-buildings/_form.blade.php` | 所在地欄の削除 / 地図の単純化 / 住所検索 JS の削除 |
| `resources/views/tenant/area-buildings/show.blade.php` | 所在地表示の削除 |
| `tests/Feature/AjaxErrorFeedbackTest.php` | `VIEWS_NULL_RETURN` に `_map.blade.php` を追加 |
| `tests/Unit/Support/VacancyRateTest.php` | `level()` の帯テストを追加 |
| `tests/Feature/Tenant/AreaBuildingListTest.php` | 帯テストを 25 / 50 へ。キーワードの住所ケースを外す |
| `tests/Feature/Tenant/AreaBuildingCrudTest.php` | 「住所が消えない」回帰テストを追加。所在地欄が画面に出ないことを追加 |
| `tests/Feature/Tenant/AreaBuildingAddressFallbackRegexTest.php` | `_form.blade.php` を名指しする第 1 ケースを削除 |

**触らないもの**（設計 §6.2）: `area_buildings.address` 列 / Excel 取込の「所在地」マッピング / `storeCoordinates()`（一括取得）/ `AreaBuilding::pendingGeocode*()`。

---

## Task 1: `VacancyRate::level()` と帯の定数

空室率の帯を判定する唯一の場所を作る。ここに集約しないと、一覧フィルタと地図の凡例が別々の閾値を持つ（Bug #41）。

**Files:**
- Modify: `app/Support/VacancyRate.php`
- Test: `tests/Unit/Support/VacancyRateTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/VacancyRateTest.php` の末尾（クラスの閉じ括弧の直前）に追加:

```php
    /**
     * 帯の境界。
     *
     * ⚠ 閾値は 2026-08-19 の実データ 187 棟で決めた（設計書 §3）。
     *   0 / 25 / 50 で 24:18:26:31 とほぼ四等分になる。20 / 40 に戻すとここが赤になる。
     *
     * ⚠ 境界は**両側から挟む**。「ちょうど 25.0%」だけを見ると `>` へ変異させても
     *   気づけない（AreaBuildingListTest が 20/40 時代に同じ穴を踏んでいる）。
     */
    public function test_level_bands(): void
    {
        $cases = [
            ['区画 0 は未調査',        0,   0,   0, VacancyRate::LEVEL_UNKNOWN],
            ['満室は none',            10,  0,   0, VacancyRate::LEVEL_NONE],
            ['1.0% は low',            99,  1,   0, VacancyRate::LEVEL_LOW],
            ['24.9% は low',           301, 100, 0, VacancyRate::LEVEL_LOW],
            ['ちょうど 25.0% は mid',  3,   1,   0, VacancyRate::LEVEL_MID],
            ['49.9% は mid',           501, 500, 0, VacancyRate::LEVEL_MID],
            ['ちょうど 50.0% は high', 1,   1,   0, VacancyRate::LEVEL_HIGH],
            ['100% は high',           0,   5,   0, VacancyRate::LEVEL_HIGH],
            ['不明も空きとして数える', 1,   0,   1, VacancyRate::LEVEL_HIGH],
        ];

        foreach ($cases as [$label, $operating, $vacant, $unknown, $expected]) {
            $this->assertSame(
                $expected,
                VacancyRate::level($operating, $vacant, $unknown),
                $label . '（営業 ' . $operating . ' / 空き ' . $vacant . ' / 不明 ' . $unknown . '）'
            );
        }
    }

    /** 凡例に使う 5 段が全部あり、色とラベルを持つこと（地図の凡例が欠けないように） */
    public function test_levels_table_covers_every_level(): void
    {
        $keys = [
            VacancyRate::LEVEL_NONE,
            VacancyRate::LEVEL_LOW,
            VacancyRate::LEVEL_MID,
            VacancyRate::LEVEL_HIGH,
            VacancyRate::LEVEL_UNKNOWN,
        ];

        $this->assertSame($keys, array_keys(VacancyRate::LEVELS), '凡例の並び順が変わっている');

        foreach (VacancyRate::LEVELS as $key => $level) {
            $this->assertArrayHasKey('label', $level, $key . ' にラベルが無い');
            $this->assertArrayHasKey('color', $level, $key . ' に色が無い');
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $level['color'], $key . ' の色が 16 進表記でない');
        }
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'test_level_bands|test_levels_table' tests/Unit/Support/VacancyRateTest.php`
Expected: FAIL — `Error: Undefined constant App\Support\VacancyRate::LEVEL_UNKNOWN`

- [ ] **Step 3: 実装する**

`app/Support/VacancyRate.php` の `SCALE` 定数の直後に追加:

```php
    /**
     * 空室率の帯（%）。⚠ **閾値はここ 1 箇所だけ。**
     * 一覧のフィルタ（AreaBuildingListService::matchesVacancy）も
     * 地図の色分け・凡例もこれを見る。別々に持つと片方だけ直す事故が起きる（Bug #41）。
     *
     * 2026-08-19 の実データ 187 棟で 24:18:26:31 に割れることを確認して決めた（設計書 §3）。
     */
    public const BAND_MID  = 25.0;

    public const BAND_HIGH = 50.0;

    public const LEVEL_NONE    = 'none';

    public const LEVEL_LOW     = 'low';

    public const LEVEL_MID     = 'mid';

    public const LEVEL_HIGH    = 'high';

    public const LEVEL_UNKNOWN = 'unknown';

    /**
     * 凡例と地図のピンの見た目。
     *
     * ⚠ 色は Tailwind クラスでなく 16 進で持つ。Google Maps のマーカーへ
     *   そのまま渡す値で、CSS クラスでは指定できないため（UnitStatus とは事情が違う）。
     */
    public const LEVELS = [
        self::LEVEL_NONE    => ['label' => '満室（0%）', 'color' => '#059669'],
        self::LEVEL_LOW     => ['label' => '1〜24%',     'color' => '#eab308'],
        self::LEVEL_MID     => ['label' => '25〜49%',    'color' => '#f97316'],
        self::LEVEL_HIGH    => ['label' => '50% 以上',   'color' => '#dc2626'],
        self::LEVEL_UNKNOWN => ['label' => '調査なし',   'color' => '#9ca3af'],
    ];
```

同ファイルの `label()` の直後（クラスの閉じ括弧の前）に追加:

```php
    /**
     * 空室率の帯。総区画数が 0（＝率が出せない）なら unknown。
     *
     * ⚠ 調査回がまだ無いビルは呼び出し側で unknown にする。ここへ null は渡せない
     *   （引数は int なので TypeError になる）。
     */
    public static function level(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::percent($operating, $vacant, $unknown);

        return match (true) {
            $rate === null           => self::LEVEL_UNKNOWN,
            $rate >= self::BAND_HIGH => self::LEVEL_HIGH,
            $rate >= self::BAND_MID  => self::LEVEL_MID,
            $rate > 0.0              => self::LEVEL_LOW,
            default                  => self::LEVEL_NONE,
        };
    }
```

- [ ] **Step 4: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Unit/Support/VacancyRateTest.php`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add app/Support/VacancyRate.php tests/Unit/Support/VacancyRateTest.php
git commit -m "feat(tenant): 空室率の帯を VacancyRate::level() に集約する"
```

---

## Task 2: 一覧フィルタの閾値を 25% / 50% へ

Task 1 の定数を一覧フィルタから使い、凡例と食い違わないようにする。

**Files:**
- Modify: `app/Services/Tenant/AreaBuildingListService.php:47-58, 199-204`
- Test: `tests/Feature/Tenant/AreaBuildingListTest.php:56-110`

- [ ] **Step 1: 既存テストを新しい閾値へ書き換える（ここが失敗するテストになる）**

`tests/Feature/Tenant/AreaBuildingListTest.php` の `test_vacancy_bands` を丸ごと置き換える:

```php
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
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over25'))
        );
        $this->assertSame(
            ['率50'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over50'))
        );
    }
```

続けて `test_vacancy_band_boundaries_are_inclusive_at_20_and_40_percent` を丸ごと置き換える（docblock も含む）:

```php
    /**
     * 空室率フィルタの境界は「以上」（inclusive）。
     *
     * ⚠ 上の test_vacancy_bands は 10% / 30% / 50% しか使っておらず、
     *   等号側を一度も踏んでいない（`>=` を `>` に変異させても緑のまま）。
     *   ちょうど 25.0% / ちょうど 50.0% のビルを作って等号側を固定し、
     *   境界のすぐ下（24.9% / 49.9%）が含まれないことで両側から挟む。
     *
     * ⚠ 閾値は VacancyRate::BAND_MID / BAND_HIGH に集約済み。20 / 40 に戻すとここが赤になる。
     */
    public function test_vacancy_band_boundaries_are_inclusive_at_25_and_50_percent(): void
    {
        $this->makeSurvey($this->makeBuilding('率ちょうど25'), '2026-08-01', 3, 1);      // 25.0%
        $this->makeSurvey($this->makeBuilding('率ちょうど50'), '2026-08-01', 5, 5);      // 50.0%
        $this->makeSurvey($this->makeBuilding('率24.9'), '2026-08-01', 301, 100);        // 24.9%
        $this->makeSurvey($this->makeBuilding('率49.9'), '2026-08-01', 501, 500);        // 49.9%

        $staff = $this->staff();

        $over25 = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over25'));
        $this->assertContains('率ちょうど25', $over25, 'ちょうど 25.0% が over25 に含まれていない（境界が inclusive でない）');
        $this->assertNotContains('率24.9', $over25, '24.9%（25.0% 未満）が over25 に含まれてしまっている');

        $over50 = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over50'));
        $this->assertContains('率ちょうど50', $over50, 'ちょうど 50.0% が over50 に含まれていない（境界が inclusive でない）');
        $this->assertNotContains('率49.9', $over50, '49.9%（50.0% 未満）が over50 に含まれてしまっている');
    }
```

`test_unknown_counts_toward_the_vacancy_band` の URL も直す（`over40` → `over50`。営業 5 / 空き 0 / 不明 5 は 50.0% なので `over50` に入る）:

```php
        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=over50');
```

さらに、閾値が 1 箇所であることを固定する構造テストを同ファイルの末尾（クラスの閉じ括弧の直前）に追加:

```php
    /**
     * 閾値の直値がサービス側に残っていないこと。
     *
     * ⚠ 値のテストだけでは守れない。`>= 25.0` と直書きしても上のテストは緑のままで、
     *   地図の凡例（VacancyRate::LEVELS）と別々に動く状態が残る（Bug #41 / #42 ②）。
     * ⚠ コメントを落としてから測る。docblock に「25%」と書いてあると false-pass する。
     */
    public function test_vacancy_filter_reads_the_shared_band_constants(): void
    {
        $source = $this->sourceWithoutComments(app_path('Services/Tenant/AreaBuildingListService.php'));

        $this->assertStringContainsString('VacancyRate::BAND_MID', $source, 'フィルタが共有の閾値定数を見ていない');
        $this->assertStringContainsString('VacancyRate::BAND_HIGH', $source, 'フィルタが共有の閾値定数を見ていない');
        $this->assertDoesNotMatchRegularExpression('/>=\s*\d+\.\d+/', $source, '閾値の直値がフィルタに残っている');
    }

    /** コメント（`//` と docblock）を落としたソース。Bug #42 ② の false-pass 対策 */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'vacancy' tests/Feature/Tenant/AreaBuildingListTest.php`
Expected: FAIL — `over25` が未知の値なので「全て」に落ち、期待と件数が合わない

- [ ] **Step 3: 定数と判定を直す**

`app/Services/Tenant/AreaBuildingListService.php` の定数ブロックを置き換える:

```php
    public const VACANCY_FULL   = 'full';
    public const VACANCY_ANY    = 'any';
    public const VACANCY_OVER25 = 'over25';
    public const VACANCY_OVER50 = 'over50';

    /**
     * フィルタバーの選択肢（「全て」は空値なのでここには入れない）。
     *
     * ⚠ ラベルの数字は VacancyRate::BAND_MID / BAND_HIGH と揃えること。
     *   閾値を動かすときは両方直す（表示だけ 25% で判定が 20% は最悪の状態）。
     */
    public const VACANCY_OPTIONS = [
        self::VACANCY_FULL   => '満室（0%）',
        self::VACANCY_ANY    => '空きあり（1%以上）',
        self::VACANCY_OVER25 => '空室率 25% 以上',
        self::VACANCY_OVER50 => '空室率 50% 以上',
    ];
```

`matchesVacancy()` の `match` を置き換える:

```php
        return match ($vacancy) {
            self::VACANCY_FULL   => $rate <= 0.0,
            self::VACANCY_ANY    => $rate > 0.0,
            // ⚠ 直値を書かない。地図の凡例と別々に閾値を持つと片方だけ直す事故が起きる（Bug #41）
            self::VACANCY_OVER25 => $rate >= VacancyRate::BAND_MID,
            self::VACANCY_OVER50 => $rate >= VacancyRate::BAND_HIGH,
        };
```

- [ ] **Step 4: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingListTest.php tests/Unit/Support/VacancyRateTest.php`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add app/Services/Tenant/AreaBuildingListService.php tests/Feature/Tenant/AreaBuildingListTest.php
git commit -m "fix(tenant): 空室率フィルタの帯を実データに合わせて 25%/50% にする"
```

---

## Task 3: 1 棟ぶんの座標保存ルート

地図クリックで置いたピンを保存する経路。**上書き可**であることが本質。

**Files:**
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Modify: `routes/web.php:492-496`
- Test: `tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;

/**
 * 地図クリックで置いた 1 棟ぶんの座標保存（設計書 §5.1）。
 *
 * ⚠ **上書きできることが本質。** 一括取得（storeCoordinates）は `whereNull('latitude')` で
 *   既存座標を守るが、こちらは人が置き直すための経路なので同じガードを持ち込んではいけない。
 *   ガードが混入すると「1 度置いたピンが二度と直せない」状態になり、しかも
 *   **保存は 200 を返す**ので画面からは成功に見える。
 */
class AreaBuildingCoordinateStoreTest extends AreaBuildingTestCase
{
    private function url(AreaBuilding $building): string
    {
        return '/tenant/area-buildings/' . $building->id . '/coordinates';
    }

    public function test_manager_can_store_coordinates(): void
    {
        $building = $this->makeBuilding('魚政ビル');

        $response = $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $response->assertOk();
        $building->refresh();
        $this->assertSame('33.8392000', (string) $building->latitude);
        $this->assertSame('132.7657000', (string) $building->longitude);
    }

    /** ⚠ ここが load-bearing。whereNull ガードが混入したら赤になる */
    public function test_existing_coordinates_are_overwritten(): void
    {
        $building = $this->makeBuilding('須山ビル', ['latitude' => 33.1, 'longitude' => 132.1]);

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8500000, 'longitude' => 132.7700000])
            ->assertOk();

        $building->refresh();
        $this->assertSame('33.8500000', (string) $building->latitude, '既存座標が上書きされていない（置き直しができない）');
        $this->assertSame('132.7700000', (string) $building->longitude);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $building = $this->makeBuilding('夢想案ビル');

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 91, 'longitude' => 132.7])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8, 'longitude' => 181])
            ->assertStatus(422);

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
    }

    public function test_both_values_are_required(): void
    {
        $building = $this->makeBuilding('京ビル');

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8])
            ->assertStatus(422);

        $building->refresh();
        $this->assertNull($building->latitude, '経度だけ欠けた行を保存してはいけない（片方だけの行は詰みになる）');
    }

    public function test_staff_cannot_store_coordinates(): void
    {
        $building = $this->makeBuilding('セイブビル');

        $this->actingAs($this->staff())
            ->postJson($this->url($building), ['latitude' => 33.8, 'longitude' => 132.7])
            ->assertForbidden();

        $this->assertNull($building->refresh()->latitude);
    }

    public function test_guest_cannot_store_coordinates(): void
    {
        $building = $this->makeBuilding('番町ビル');

        $this->post($this->url($building), ['latitude' => 33.8, 'longitude' => 132.7])
            ->assertRedirect('/login');

        $this->assertNull($building->refresh()->latitude);
    }

    /**
     * 一括取得の二重課金ガードを緩めていないこと。
     *
     * ⚠ 新しい経路を足したついでに共通化して、あちらのガードまで外す事故を防ぐ。
     */
    public function test_bulk_geocode_still_refuses_to_touch_existing_coordinates(): void
    {
        $building = $this->makeBuilding('手で直した棟', [
            'address'   => '愛媛県松山市一番町1-1',
            'latitude'  => 33.1234567,
            'longitude' => 132.1234567,
        ]);

        $this->actingAs($this->manager())->post('/tenant/area-buildings/geocode', [
            'coordinates' => json_encode([
                ['id' => $building->id, 'latitude' => 34.0, 'longitude' => 133.0],
            ]),
        ]);

        $building->refresh();
        $this->assertSame('33.1234567', (string) $building->latitude, '一括取得が手入力の座標を潰している');
    }
}
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php`
Expected: FAIL — 404（ルート未定義）

- [ ] **Step 3: コントローラにアクションを足す**

`app/Http/Controllers/Tenant/AreaBuildingController.php` の `storeCoordinates()` の**直後**に追加:

```php
    /**
     * 地図上でクリックして置いた 1 棟ぶんの座標（設計書 §5.1）。
     *
     * ⚠ **既存座標を上書きする。** 置き直せることが目的なので、一括取得
     *   （storeCoordinates）の `whereNull('latitude')` ガードをここへ持ち込まない。
     *   あちらは「機械が引いた座標で人の手入力を潰さない」ためのもので、目的が逆。
     *
     * ⚠ 緯度と経度は必ず対で受ける（片方だけの行は hasCoordinates() が false なのに
     *   一括取得の対象にもならない詰み行になる。AreaBuilding の saving フック参照）。
     *
     * ⚠ ルールは literal 配列で直書きする。閉じ括弧を行頭に置かないと
     *   JapaneseValidationMessagesTest の走査から外れる（store() のコメント参照）。
     */
    public function storeCoordinate(Request $request, AreaBuilding $building)
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $building->update([
            'latitude'  => round((float) $validated['latitude'], 7),
            'longitude' => round((float) $validated['longitude'], 7),
        ]);

        return response()->json([
            'id'        => $building->id,
            'latitude'  => (float) $building->latitude,
            'longitude' => (float) $building->longitude,
        ]);
    }
```

- [ ] **Step 4: ルートを足す**

`routes/web.php` の「編集・更新（経営層+管理者）」グループ（`Route::put('/area-buildings/{building}', ...)` の直後、グループの閉じ括弧の前）に追加:

```php
            // 地図クリックで置いた座標（1 棟ずつ・上書き可）。
            // ⚠ 一括取得の /area-buildings/geocode とは別物。あちらは住所ベースで上書き不可
            Route::post('/area-buildings/{building}/coordinates', [\App\Http\Controllers\Tenant\AreaBuildingController::class, 'storeCoordinate'])
                ->name('tenant.area-buildings.coordinates');
```

- [ ] **Step 5: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php`
Expected: OK (7 tests)

- [ ] **Step 6: 和名チェックが通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/JapaneseValidationMessagesTest.php`
Expected: OK（`latitude` = 緯度 / `longitude` = 経度 は `lang/ja/validation.php:317-318` に既にある）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/Tenant/AreaBuildingController.php routes/web.php tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php
git commit -m "feat(tenant): 地図で置いた座標を 1 棟ずつ保存するルートを足す"
```

---

## Task 4: 編集で住所が消えないようにする

**Task 5 より先にやること。** 先に欄を消すと、この欠陥が本番データを壊す。

`update()` は `'address' => $validated['address'] ?? null` と書いている。フォームから所在地欄が消えると `address` が送られなくなり、**Excel 取込で入った住所が編集保存のたびに NULL へ落ちる**（Bug #38 と同型。画面には出ないので誰も気づけない）。

**Files:**
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`（`store()` / `update()`）
- Test: `tests/Feature/Tenant/AreaBuildingCrudTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingCrudTest.php` の末尾（クラスの閉じ括弧の直前）に追加:

```php
    /**
     * 所在地を送らない更新で、既存の住所が消えないこと。
     *
     * ⚠ 2026-08-19 に所在地の入力欄を画面から外したので、**実運用の編集は必ずこの形**になる。
     *   `'address' => $validated['address'] ?? null` のままだと、Excel 取込で入った住所が
     *   編集保存のたびに NULL へ落ちる（Bug #38 と同型。画面に出ないので誰も気づけない）。
     */
    public function test_update_without_address_keeps_the_existing_address(): void
    {
        $building = $this->makeBuilding('取込で入った棟', ['address' => '愛媛県松山市一番町1-1']);

        $this->actingAs($this->manager())
            ->put('/tenant/area-buildings/' . $building->id, [
                'name'         => '取込で入った棟',
                'total_floors' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(
            '愛媛県松山市一番町1-1',
            $building->refresh()->address,
            '所在地を送らない更新で既存の住所が消えている'
        );
    }

    /** 送れば今までどおり更新できる（サーバ側の受け口は残してある。設計書 §6.2） */
    public function test_update_with_address_still_updates_it(): void
    {
        $building = $this->makeBuilding('住所を直す棟', ['address' => '旧住所']);

        $this->actingAs($this->manager())
            ->put('/tenant/area-buildings/' . $building->id, [
                'name'    => '住所を直す棟',
                'address' => '新住所',
            ])
            ->assertRedirect();

        $this->assertSame('新住所', $building->refresh()->address);
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'address' tests/Feature/Tenant/AreaBuildingCrudTest.php`
Expected: FAIL — `test_update_without_address_keeps_the_existing_address` が `Failed asserting that null is identical to '愛媛県松山市一番町1-1'`

- [ ] **Step 3: 実装する**

`update()` の `$building->update([...])` を置き換える:

```php
        // ⚠ 送られてこなかったキーは触らない。所在地は 2026-08-19 に画面から外したので
        //   実運用の更新には含まれない。`?? null` のままだと Excel 取込で入った住所が
        //   編集のたびに消える（Bug #38 と同型）。
        $changes = [
            'name'         => $validated['name'],
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ];

        if (array_key_exists('address', $validated)) {
            $changes['address'] = $validated['address'];
        }

        $building->update($changes);
```

`store()` の `AreaBuilding::create([...])` は**そのままでよい**（新規作成なので消える既存値が無い）。

- [ ] **Step 4: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingCrudTest.php`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/AreaBuildingController.php tests/Feature/Tenant/AreaBuildingCrudTest.php
git commit -m "fix(tenant): 所在地を送らない更新で既存の住所が消えないようにする"
```

---

## Task 5: 所在地を画面から消す

**Files:**
- Modify: `resources/views/tenant/area-buildings/index.blade.php:45, 105-115, 121, 140-142, 183`
- Modify: `resources/views/tenant/area-buildings/show.blade.php:42-45`
- Modify: `resources/views/tenant/area-buildings/_form.blade.php:18-24`
- Modify: `app/Services/Tenant/AreaBuildingListService.php:148-156`
- Test: `tests/Feature/Tenant/AreaBuildingCrudTest.php` / `AreaBuildingListTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingCrudTest.php` の末尾に追加:

```php
    /**
     * 所在地は画面に出さない（設計書 §6.1）。住所が分からない運用なので常に空欄になり、
     * 入力欄と列がノイズにしかならない。
     *
     * ⚠ **DB 列と Excel 取込のマッピングは残す**（§6.2）。消しすぎの検出も対で置く。
     */
    public function test_address_is_not_shown_on_any_screen(): void
    {
        $building = $this->makeBuilding('住所つきの棟', ['address' => '愛媛県松山市一番町1-1']);
        $manager  = $this->manager();

        foreach ([
            '/tenant/area-buildings',
            '/tenant/area-buildings/' . $building->id,
            '/tenant/area-buildings/create',
            '/tenant/area-buildings/' . $building->id . '/edit',
        ] as $url) {
            $html = $this->actingAs($manager)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('所在地', $html, $url . ' に「所在地」が残っている');
            $this->assertStringNotContainsString('name="address"', $html, $url . ' に住所の入力欄が残っている');
            $this->assertStringNotContainsString('愛媛県松山市一番町1-1', $html, $url . ' に住所の値が出ている');
        }
    }

    /** 消しすぎの検出。DB 列と取込の受け口は生きていること（設計書 §6.2） */
    public function test_address_is_still_accepted_by_the_database_and_the_importer(): void
    {
        $building = $this->makeBuilding('取込棟', ['address' => '愛媛県松山市二番町2-2']);
        $this->assertSame('愛媛県松山市二番町2-2', $building->refresh()->address, 'DB 列が消えている');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/import')->getContent();
        $this->assertStringContainsString("label: '所在地'", $html, 'Excel 取込の「所在地」マッピングが消えている');
    }
```

`tests/Feature/Tenant/AreaBuildingListTest.php` の `test_keyword_searches_name_address_and_current_tenants` を置き換える:

```php
    /**
     * キーワードはビル名と在籍テナント名で引く。
     *
     * ⚠ 所在地は 2026-08-19 に検索対象から外した（画面に出していない項目で
     *   ヒットすると、利用者からは「なぜこの棟が出るのか」が分からない）。
     */
    public function test_keyword_searches_name_and_current_tenants_but_not_address(): void
    {
        $this->makeBuilding('大街道ビル');
        $this->makeBuilding('住所だけ一致ビル', ['address' => '愛媛県松山市大街道1-1']);

        $withTenant = $this->makeBuilding('テナント持ちビル');
        $this->makeTenant($withTenant, ['name' => '大街道珈琲']);

        $this->makeBuilding('無関係ビル');

        $names = $this->listedNames(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('大街道'))
        );

        sort($names);
        $this->assertSame(['テナント持ちビル', '大街道ビル'], $this->sortedJa($names));
        $this->assertNotContains('住所だけ一致ビル', $names, '検索対象から外したはずの所在地でヒットしている');
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'address|keyword_searches' tests/Feature/Tenant/AreaBuildingCrudTest.php tests/Feature/Tenant/AreaBuildingListTest.php`
Expected: FAIL — 一覧・詳細・フォームに「所在地」が残っている / 住所でヒットしている

- [ ] **Step 3: キーワード検索から住所を外す**

`app/Services/Tenant/AreaBuildingListService.php` の `baseQuery()` の `$query->where(function ...)` を置き換える:

```php
            $query->where(function (Builder $q) use ($like) {
                // ⚠ 所在地は画面に出していないので検索対象にもしない（設計書 §6.1）
                $q->where('area_buildings.name', 'like', $like)
                    // 現況の行だけ。退去済みまで拾うと「もう居ない会社」でヒットする
                    ->orWhereHas('tenants', fn ($t) => $t->whereNull('moved_out_on')->where('name', 'like', $like));
            });
```

- [ ] **Step 4: 一覧から所在地列を消す**

`resources/views/tenant/area-buildings/index.blade.php`:

1. 検索ボックスの placeholder（45 行目付近）:

```blade
               placeholder="ビル名・テナント名"
```

2. `<colgroup>` を置き換える（**所在地の 20% を削り、合計を 100% に戻す**。ビル名 18→24%、最終調査 11→13%、操作 11→14%、位置 8→11%）:

```blade
                    {{-- ⚠ 列を足し引きするときは colgroup の合計 100% / th の本数 /
                         空行の colspan を 3 点セットで揃える（Task 10 で合計 106% にした前科あり） --}}
                    <colgroup>
                        <col style="width:24%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:8%">
                        <col style="width:11%">
                        <col style="width:13%">
                        <col style="width:20%">
                    </colgroup>
```

3. `<thead>` から所在地の `<th>` を 1 行削除する:

```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所在地</th>
```

4. `<tbody>` から所在地の `<td>` を削除する:

```blade
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700">
                                    {{ $row['building']->address ?: '—' }}
                                </td>
```

5. 空行の colspan を `10` → `9` に直す:

```blade
                                <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">
```

- [ ] **Step 5: 詳細から所在地を消す**

`resources/views/tenant/area-buildings/show.blade.php` のヘッダ部を置き換える（2 カラムのうち所在地を落として総階数だけにする）:

```blade
    {{-- ヘッダ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <div class="text-xs font-semibold text-gray-500 mb-1">総階数</div>
                <div class="text-sm text-gray-800">{{ $building->totalFloorsLabel() }}</div>
            </div>
        </div>
```

- [ ] **Step 6: フォームから所在地欄を消し、地図セクションの見出しを直す**

`resources/views/tenant/area-buildings/_form.blade.php`:

1. 所在地ブロック（18〜24 行目付近、`<label ...>所在地</label>` を含む `<div>` 一式）を削除する。削除後、その `grid` の残りが 1 列にならないことを目視で確認する（ビル名・総階数が並ぶ形）

2. ⚠ **セクション見出し「所在地マップ」も直す。** これは画面に出る文字列なので、残すと
   `test_address_is_not_shown_on_any_screen` が落ちる（実測でここに `所在地` がある）:

```blade
{{-- 位置（地図でピンを置く） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">位置</div>
```

- [ ] **Step 7: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/`
Expected: OK

⚠ ここで `AreaBuildingAddressFallbackRegexTest` が落ちる場合は Task 6 で扱うので、いったん `--filter` から外して先へ進まないこと。Task 6 まで通しでやってからコミットする。

- [ ] **Step 8: コミット**

```bash
git add resources/views/tenant/area-buildings app/Services/Tenant/AreaBuildingListService.php tests/Feature/Tenant
git commit -m "feat(tenant): 周辺ビル調査の画面から所在地を外す"
```

---

## Task 6: フォームの地図を単純化し、到達不能になった住所検索 JS を消す

所在地欄が無くなったので `geocodeAreaAddress()` は必ず「住所が空」の分岐に落ちる。到達不能なコードを残さない。

**Files:**
- Modify: `resources/views/tenant/area-buildings/_form.blade.php:34-50, 111-220`
- Modify: `tests/Feature/Tenant/AreaBuildingAddressFallbackRegexTest.php`
- Test: `tests/Feature/Tenant/AreaBuildingCrudTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingCrudTest.php` の末尾に追加:

```php
    /**
     * フォームの地図は「押したら開く」だけにする（設計書 §6.3）。
     *
     * ⚠ 所在地欄が無くなった以上、住所検索の JS は**必ず空の分岐に落ちる到達不能コード**。
     *   残すと「動かないコードが居座る」うえ、次に読む人が住所検索が生きていると誤解する。
     */
    public function test_the_form_map_no_longer_searches_by_address(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        foreach (['geocodeAreaAddress', 'buildAreaAddressFallbacks', 'tryGeocodeAreaCandidates', 'new google.maps.Geocoder'] as $dead) {
            $this->assertStringNotContainsString($dead, $html, '到達不能になった住所検索の JS が残っている: ' . $dead);
        }

        $this->assertStringContainsString('地図で位置を指定', $html, '地図を開くボタンが無い');
        $this->assertStringContainsString('openAreaMap()', $html, '地図を開くボタンが配線されていない');
    }

    /** ピンのドラッグと地図クリックで座標が入る仕掛けは残っていること */
    public function test_the_form_map_still_places_a_pin(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        $this->assertStringContainsString('id="input-latitude"', $html);
        $this->assertStringContainsString('id="input-longitude"', $html);
        $this->assertStringContainsString("addListener('click'", $html, '地図クリックでピンを置く配線が消えている');
        $this->assertStringContainsString('draggable', $html, 'ピンのドラッグが消えている');
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'form_map' tests/Feature/Tenant/AreaBuildingCrudTest.php`
Expected: FAIL — `geocodeAreaAddress` が残っている

- [ ] **Step 3: ボタンを差し替える**

`resources/views/tenant/area-buildings/_form.blade.php` のボタン行（`id="btn-geocode"` の `<button>` とその隣の `<span>`）を置き換える:

```blade
        <button type="button" id="btn-open-map" onclick="openAreaMap()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            地図で位置を指定
        </button>
        <span class="text-xs text-gray-500">地図をクリック、またはピンをドラッグして位置を決めます</span>
```

- [ ] **Step 4: 住所検索の JS を消し、地図を開く関数に差し替える**

`_form.blade.php` の `<script>` 内、`var areaGeocoder = null;` から `geocodeAreaAddress()` の閉じ括弧までを削除し、代わりに次を置く（`onGoogleMapsReady` / `showAreaMap` / `showAreaMapStatus` は**残す**）:

```javascript
var areaMap = null;
var areaMarker = null;
var AREA_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };
var areaMapsReady = false;

function onGoogleMapsReady() {
    areaMapsReady = true;

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showAreaMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 地図を開くだけ。住所からの検索はしない（所在地欄が無いので。設計書 §6.3）
function openAreaMap() {
    if (!areaMapsReady) {
        showAreaMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;

    if (savedLat && savedLng) {
        showAreaMap(parseFloat(savedLat), parseFloat(savedLng), 17);
        showAreaMapStatus('地図をクリック、またはピンをドラッグして位置を調整できます。', '#dbeafe', '#1e40af');
        return;
    }

    showAreaMap(AREA_DEFAULT_CENTER.lat, AREA_DEFAULT_CENTER.lng, AREA_DEFAULT_CENTER.zoom);
    showAreaMapStatus('松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
}
```

Google Maps の読み込み `<script>` の `callback=onGoogleMapsReady` は**そのまま**。

- [ ] **Step 5: フォールバックのテストを整理する**

`tests/Feature/Tenant/AreaBuildingAddressFallbackRegexTest.php` から、`_form.blade.php` を名指しするケース（`resource_path('views/tenant/area-buildings/_form.blade.php')` を読むテストメソッド 1 本）を**削除**し、クラスの docblock に理由を追記する:

```php
 * ⚠ 2026-08-19: `_form.blade.php` の段階フォールバックを名指しで見ていたケースを削除した。
 *   所在地の入力欄を画面から外したので、フォームの住所検索そのものが無くなったため。
 *   ⚠ **一括取得（index.blade.php）側へは移していない。** 設計書 §6.1 に
 *   「1 クリックで最大 5 回ジオコーディングを叩く。一括処理でこの関数を使い回さないこと」と
 *   あり、移すと一括取得の費用が最大 5 倍になる。
 *   `resources/views` 全体を走査する残りのケースはそのまま有効。
```

- [ ] **Step 6: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/`
Expected: OK

- [ ] **Step 7: コミット**

```bash
git add resources/views/tenant/area-buildings/_form.blade.php tests/Feature/Tenant
git commit -m "refactor(tenant): 所在地欄の削除で到達不能になった住所検索 JS を消す"
```

---

## Task 7: 地図タブの器

**表タブで地図の JS が 1 行も出ないこと**が本タスクの本質（課金方針そのもの。設計書 §7）。

**Files:**
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php:29-51`
- Modify: `app/Services/Tenant/AreaBuildingListService.php:60-80`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`
- Create: `resources/views/tenant/area-buildings/_map.blade.php`
- Test: `tests/Feature/Tenant/AreaBuildingMapTabTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Tenant;

/**
 * 一覧の地図タブ（設計書 §4）。
 *
 * ⚠ **表タブで地図を生成しないことが課金方針そのもの**（§7）。
 *   Maps JavaScript API は `new google.maps.Map()` の実行ごとに課金されるので、
 *   表で見ているだけの利用者に地図を作らせてはいけない。
 */
class AreaBuildingMapTabTest extends AreaBuildingTestCase
{
    public function test_the_table_tab_is_the_default_and_loads_no_map(): void
    {
        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->assertOk()->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html, '表タブで Google Maps を読み込んでいる（課金が発生する）');
        $this->assertStringNotContainsString('new google.maps.Map(', $html, '表タブで地図を生成している');
        $this->assertStringContainsString('<table', $html, '表タブなのに表が出ていない');
    }

    public function test_the_map_tab_loads_the_map_and_hides_the_table(): void
    {
        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->assertOk()->getContent();

        $this->assertStringContainsString('maps.googleapis.com', $html, '地図タブで Google Maps を読み込んでいない');
        $this->assertStringContainsString('id="area-map"', $html, '地図の器が無い');
        // ⚠ `<table` で見ない。レイアウト側に表が増えたときに巻き込まれる。
        //   一覧の表にしか無い `<colgroup` で見る
        $this->assertStringNotContainsString('<colgroup', $html, '地図タブで一覧の表も描画している');
    }

    public function test_both_tabs_are_linked_and_keep_the_filters(): void
    {
        $html = $this->actingAs($this->staff())
            ->get('/tenant/area-buildings?vacancy=over25&keyword=' . urlencode('番町'))
            ->getContent();

        $this->assertStringContainsString('vacancy=over25', $html);
        $this->assertStringContainsString('view=map', $html, '地図タブへのリンクが無い');
    }

    public function test_the_map_tab_is_not_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding('棟' . $i, ['latitude' => 33.84 + $i / 1000, 'longitude' => 132.76]);
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map');

        // 25 棟すべてがピンデータに載っていること（表は 20 件/頁だが地図は全件）
        // ⚠ HTML の文字列で数えてはいけない。`Js::from()` は JSON_HEX_QUOT で `"` を
        //   `"` に、日本語も `\uXXXX` に変換するので `'"name":"棟'` は 1 件も一致しない
        //   （実測で確認済み。ここを文字列で書くと「常に 0 件」＝常に赤になる）。
        $this->assertCount(25, $response->viewData('mapPins'), '地図タブがページングされている（ピンが欠けている）');
    }

    public function test_unlocated_buildings_are_reported_not_silently_dropped(): void
    {
        $this->makeBuilding('座標あり', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('座標なし');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertStringContainsString('位置未登録 1 棟', $html, '地図に出せない棟の件数が画面に出ていない');
    }

    public function test_the_legend_uses_the_shared_levels(): void
    {
        $this->makeBuilding('棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();

        foreach (\App\Support\VacancyRate::LEVELS as $level) {
            $this->assertStringContainsString($level['label'], $html, '凡例に ' . $level['label'] . ' が無い');
            $this->assertStringContainsString($level['color'], $html, '凡例の色が共有定数から来ていない');
        }
    }
}
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingMapTabTest.php`
Expected: FAIL — `id="area-map"` が無い

- [ ] **Step 3: サービスに `paginateRows()` を切り出す**

`app/Services/Tenant/AreaBuildingListService.php` の `paginate()` を置き換える:

```php
    public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        return $this->paginateRows($this->rows($request), $request, $perPage);
    }

    /**
     * 組み立て済みの行をページャに載せる。
     *
     * ⚠ 地図タブは全件（rows）とページャの両方を要るので、この形にしないと
     *   1 リクエストで rows() が 2 回走る。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function paginateRows(Collection $rows, Request $request, int $perPage = 20): LengthAwarePaginator
    {
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
```

- [ ] **Step 4: コントローラに地図タブの分岐を足す**

`use App\Support\VacancyRate;` を import に追加し、`index()` を置き換える:

```php
    public function index(Request $request, AreaBuildingListService $service)
    {
        $canEdit = $request->user()->role->isManagerOrAbove();
        $isMap   = $request->query('view') === 'map';

        // 座標未取得の候補は、ボタンを出す人にだけ渡す（画面に住所を撒かない・無駄な検索もしない）
        $pendingCount = $canEdit ? AreaBuilding::pendingGeocodeCount() : 0;
        $pending      = $pendingCount > 0
            ? AreaBuilding::pendingGeocode(self::GEOCODE_BATCH_LIMIT)
                ->map(fn (AreaBuilding $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])
                ->values()
                ->all()
            : [];

        // ⚠ rows() は 1 回だけ呼ぶ（地図タブは全件とページャの両方を使う）
        $rows = $service->rows($request);

        return view('tenant.area-buildings.index', [
            'rows'                => $service->paginateRows($rows, $request),
            'surveyYears'         => $service->surveyYears(),
            'vacancyOptions'      => AreaBuildingListService::VACANCY_OPTIONS,
            'pendingGeocode'      => $pending,
            'pendingGeocodeCount' => $pendingCount,
            'geocodeBatchLimit'   => self::GEOCODE_BATCH_LIMIT,
            'isMap'               => $isMap,
            'canEdit'             => $canEdit,
            // ⚠ 地図タブのときだけ組み立てる。表タブでは使わない配列を作らない
            'mapPins'             => $isMap ? $this->mapPins($rows) : [],
            'mapUnlocated'        => $isMap ? $this->mapUnlocated($rows) : [],
            'mapLevels'           => VacancyRate::LEVELS,
        ]);
    }

    /**
     * 地図のピン。
     *
     * ⚠ **必ずここで組み立てて単一変数として Blade へ渡す。** Blade の `@json()` に
     *   多行の配列リテラルやメソッド呼び出しを書くと壊れた PHP にコンパイルされる（Bug #26）。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapPins(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $row) => $row['building']->hasCoordinates())
            ->map(fn (array $row) => [
                'id'   => $row['building']->id,
                'name' => $row['building']->name,
                'lat'  => (float) $row['building']->latitude,
                'lng'  => (float) $row['building']->longitude,
                // ⚠ 調査回がまだ無い棟は operating が null。level() は int しか受けない
                'level'     => $row['operating'] === null
                    ? VacancyRate::LEVEL_UNKNOWN
                    : VacancyRate::level($row['operating'], $row['vacant'], $row['unknown']),
                'rateLabel' => $row['rate_label'],
                'floors'    => $row['building']->totalFloorsLabel(),
                'operating' => $row['operating'],
                'vacant'    => $row['vacant'],
                'unknown'   => $row['unknown'],
                'month'     => $row['month'] ? $row['month']->format('Y年n月') : '—',
                'url'       => route('tenant.area-buildings.show', $row['building']),
            ])
            ->values()
            ->all();
    }

    /**
     * 座標が無くて地図に出せない棟。件数の表示と、登録モードの作業リストに使う。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{id: int, name: string}>
     */
    private function mapUnlocated(Collection $rows): array
    {
        return $rows
            ->reject(fn (array $row) => $row['building']->hasCoordinates())
            ->map(fn (array $row) => [
                'id'   => $row['building']->id,
                'name' => $row['building']->name,
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 5: 一覧にタブを足し、表と地図を出し分ける**

`resources/views/tenant/area-buildings/index.blade.php` のフィルターバー（`</form>`）の直後、座標一括取得ブロックの**前**に追加:

```blade
    {{-- 表示切替。⚠ 既定は「表」＝地図を作らない＝課金ゼロ（設計書 §7） --}}
    @php($tabQuery = request()->except(['view', 'page']))
    <div class="flex gap-1 mb-4">
        <a href="{{ route('tenant.area-buildings.index', $tabQuery) }}"
           class="px-4 py-2 text-sm font-semibold rounded-md border transition-colors {{ $isMap ? 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-emerald-600 border-emerald-600 text-white' }}">
            表
        </a>
        <a href="{{ route('tenant.area-buildings.index', array_merge($tabQuery, ['view' => 'map'])) }}"
           class="px-4 py-2 text-sm font-semibold rounded-md border transition-colors {{ $isMap ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' }}">
            地図
        </a>
    </div>
```

次に、テーブルのブロック全体（`{{-- テーブル --}}` から、ページネーションを含む閉じ `</div>` まで）を `@if` で包む:

```blade
    @if($isMap)
        @include('tenant.area-buildings._map')
    @else
    {{-- テーブル --}}
    ...（既存のまま）...
    @endif
```

- [ ] **Step 6: `_map.blade.php` の器だけ作る**

`resources/views/tenant/area-buildings/_map.blade.php` を新規作成（JS は Task 8 / 9 で足す）:

```blade
{{-- 周辺ビル調査の地図タブ（設計書 §4）。
     ⚠ このファイルは ?view=map のときだけ include される。表タブでは Google Maps を
        1 行も読み込まない＝課金ゼロ（設計書 §7）。 --}}

@push('styles')
<style>
    /* ⚠ minmax(0, 1fr) にする。素の 1fr は min-content 幅で下限を作るので、
       Google Maps が canvas に inline の px 幅を書き込むと <main> に横スクロールが出る（Bug #29） */
    .area-map-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
    @media (min-width: 768px) {
        .area-map-layout.is-locating { grid-template-columns: 260px minmax(0, 1fr); }
    }
    #area-map { height: 60vh; min-height: 320px; max-width: 100%; border-radius: 8px; border: 1px solid #d1d5db; }
</style>
@endpush

<div class="bg-white rounded-lg border border-gray-200 p-4">

    {{-- 凡例 --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
        <div class="flex flex-wrap gap-3">
            @foreach($mapLevels as $level)
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:{{ $level['color'] }};"></span>
                    {{ $level['label'] }}
                </span>
            @endforeach
        </div>
    </div>

    <p class="text-xs text-gray-500 mb-2">
        地図に出ているのは位置を登録済みの {{ count($mapPins) }} 棟です。
        @if(count($mapUnlocated) > 0)
            <strong class="text-amber-700">位置未登録 {{ count($mapUnlocated) }} 棟</strong>
        @endif
    </p>

    <div id="area-map-layout" class="area-map-layout">
        <div id="area-map"></div>
    </div>

    <p id="area-map-status" aria-live="polite" class="mt-2 text-xs text-gray-600"></p>
</div>
```

- [ ] **Step 7: 地図の JS（閲覧のみ）を足す**

`_map.blade.php` の末尾に追加:

```blade
@push('scripts')
<script>
// ⚠ データはコントローラで組み立て済みの単一変数を受ける（Bug #23 / #26）
var AREA_MAP_PINS   = {{ \Illuminate\Support\Js::from($mapPins) }};
var AREA_MAP_LEVELS = {{ \Illuminate\Support\Js::from($mapLevels) }};
var AREA_MAP_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

var areaMapInstance = null;
var areaMapInfoWindow = null;
var areaMapMarkers = {};

/** ステータス行への表示。⚠ 握り潰さないための出口（Bug #45） */
function showMessage(text, isError) {
    var el = document.getElementById('area-map-status');
    if (!el) { return; }
    el.textContent = text;
    el.style.color = isError ? '#b91c1c' : '#4b5563';
}

function areaMapEscape(value) {
    var div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
}

function areaMapMarkerIcon(level) {
    var color = (AREA_MAP_LEVELS[level] || AREA_MAP_LEVELS.unknown).color;
    return {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 7,
        fillColor: color,
        fillOpacity: 0.95,
        strokeColor: '#ffffff',
        strokeWeight: 2
    };
}

function areaMapInfoHtml(pin) {
    return '<div style="font-size:12px; line-height:1.6; min-width:180px;">'
        + '<div style="font-weight:700; margin-bottom:4px;">' + areaMapEscape(pin.name) + '</div>'
        + '<div>総階数: ' + areaMapEscape(pin.floors) + '</div>'
        + '<div>営業 ' + areaMapEscape(pin.operating === null ? '—' : pin.operating)
        + ' / 空き ' + areaMapEscape(pin.vacant === null ? '—' : pin.vacant)
        + ' / 不明 ' + areaMapEscape(pin.unknown === null ? '—' : pin.unknown) + '</div>'
        + '<div>空室率: <strong>' + areaMapEscape(pin.rateLabel) + '</strong></div>'
        + '<div style="color:#6b7280;">最終調査: ' + areaMapEscape(pin.month) + '</div>'
        + '<a href="' + areaMapEscape(pin.url) + '" style="color:#059669; font-weight:600;">詳細を開く</a>'
        + '</div>';
}

function addAreaMapMarker(pin) {
    var marker = new google.maps.Marker({
        position: { lat: pin.lat, lng: pin.lng },
        map: areaMapInstance,
        title: pin.name,
        icon: areaMapMarkerIcon(pin.level)
    });

    marker.addListener('click', function () {
        areaMapInfoWindow.setContent(areaMapInfoHtml(pin));
        areaMapInfoWindow.open(areaMapInstance, marker);
    });

    areaMapMarkers[pin.id] = marker;
}

function onAreaMapReady() {
    areaMapInstance = new google.maps.Map(document.getElementById('area-map'), {
        center: AREA_MAP_CENTER,
        zoom: AREA_MAP_CENTER.zoom,
        mapTypeControl: true,
        // ⚠ 出すと利用者が開いた回数だけ Street View が課金される（設計書 §7）
        streetViewControl: false
    });
    areaMapInfoWindow = new google.maps.InfoWindow();

    AREA_MAP_PINS.forEach(addAreaMapMarker);

    if (AREA_MAP_PINS.length > 0) {
        var bounds = new google.maps.LatLngBounds();
        AREA_MAP_PINS.forEach(function (pin) { bounds.extend({ lat: pin.lat, lng: pin.lng }); });
        areaMapInstance.fitBounds(bounds);
    }
}

function onAreaMapFailed() {
    showMessage('地図を読み込めませんでした。通信環境を確認してページを再読み込みしてください。', true);
}
</script>
{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17）
     ⚠ onerror が無いと、読み込めなかったときに画面が無言のまま止まる --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onAreaMapReady&language=ja&region=JP"
        onerror="onAreaMapFailed()" async defer></script>
@endpush
```

- [ ] **Step 8: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingMapTabTest.php`
Expected: OK (6 tests)

- [ ] **Step 9: 全体が壊れていないことを確認する**

Run: `vendor/bin/phpunit`
Expected: OK

- [ ] **Step 10: コミット**

```bash
git add app resources routes tests
git commit -m "feat(tenant): 周辺ビル調査の一覧に地図タブを足す"
```

---

## Task 8: 登録モード

**Files:**
- Modify: `resources/views/tenant/area-buildings/_map.blade.php`
- Modify: `tests/Feature/AjaxErrorFeedbackTest.php:42-82`
- Test: `tests/Feature/Tenant/AreaBuildingMapTabTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingMapTabTest.php` の末尾に追加:

```php
    public function test_the_locate_panel_is_only_offered_to_managers(): void
    {
        $this->makeBuilding('座標なし');

        $managerHtml = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();
        $this->assertStringContainsString('id="btn-locate-mode"', $managerHtml, '管理者に登録モードのトグルが出ていない');

        $staffHtml = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();
        $this->assertStringNotContainsString('id="btn-locate-mode"', $staffHtml, 'staff に登録モードのトグルが出ている');
    }

    public function test_the_locate_list_carries_the_unlocated_buildings(): void
    {
        $this->makeBuilding('座標あり', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('まだの棟A');
        $this->makeBuilding('まだの棟B');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertStringContainsString('まだの棟A', $html);
        $this->assertStringContainsString('まだの棟B', $html);
        $this->assertStringContainsString('AREA_MAP_UNLOCATED', $html, '登録モードの作業リストが渡っていない');
        $this->assertStringContainsString('/coordinates', $html, '保存先の URL が渡っていない');
    }

    /**
     * 保存後に地図を動かさないこと。
     *
     * ⚠ 隣接する棟が続けて出てくるので、保存のたびに setCenter すると毎回探し直しになる。
     *   振る舞いは PHP からは測れないので、**動かす API を呼んでいないこと**で固定する。
     */
    public function test_saving_a_pin_does_not_recenter_the_map(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/area-buildings/_map.blade.php'));
        $body  = $this->jsFunctionBody($blade, 'saveCoordinate');

        $this->assertStringNotContainsString('setCenter', $body, '保存後に地図の中心を動かしている');
        $this->assertStringNotContainsString('setZoom', $body, '保存後に地図のズームを動かしている');
        $this->assertStringNotContainsString('fitBounds', $body, '保存後に地図の表示範囲を動かしている');
    }

    /** `name: function (…) { … }` / `function name(…) { … }` の body を波括弧の対応で切り出す（Bug #45 ④） */
    private function jsFunctionBody(string $blade, string $name): string
    {
        $at = strpos($blade, 'function ' . $name . '(');
        $this->assertNotFalse($at, $name . ' の定義が見つからない');

        $open = strpos($blade, '{', strpos($blade, ')', $at));
        $this->assertNotFalse($open, $name . ' の body が開いていない');

        $depth = 0;
        for ($i = $open; $i < strlen($blade); $i++) {
            if ($blade[$i] === '{') {
                $depth++;
            } elseif ($blade[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($blade, $open, $i - $open + 1);
                }
            }
        }

        $this->fail($name . ' の body が閉じていない');
    }
```

- [ ] **Step 2: 落ちることを確認する**

Run: `vendor/bin/phpunit --filter 'locate|recenter' tests/Feature/Tenant/AreaBuildingMapTabTest.php`
Expected: FAIL — `id="btn-locate-mode"` が無い

- [ ] **Step 3: markup を足す**

`_map.blade.php` の凡例ブロック内、`</div>` の直前（凡例の右側）に追加:

```blade
        @if($canEdit && count($mapUnlocated) > 0)
            <button type="button" id="btn-locate-mode" onclick="toggleLocateMode()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md transition-colors whitespace-nowrap">
                位置を登録
            </button>
        @endif
```

`<div id="area-map-layout" class="area-map-layout">` の中、`<div id="area-map"></div>` の**前**に追加:

```blade
        <div id="locate-panel" style="display:none;" class="border border-gray-200 rounded-md p-3 bg-gray-50">
            <div class="text-xs font-bold text-gray-700 mb-1">位置を登録する棟</div>
            <div class="text-xs text-gray-500 mb-2">
                地図をクリックすると保存して次の棟へ進みます。
                残り <strong id="locate-remaining">{{ count($mapUnlocated) }}</strong> 棟
            </div>
            <button type="button" onclick="skipLocateTarget()"
                    class="mb-2 px-3 py-1 border border-gray-300 bg-white text-xs text-gray-700 rounded hover:bg-gray-50">
                この棟を飛ばす
            </button>
            {{-- ⚠ リストは JS で描き替えるが、初期表示は Blade で静的に出す（Bug #16 の流儀） --}}
            <ul id="locate-list" style="max-height: 46vh; overflow-y: auto; margin:0; padding:0; list-style:none;">
                @foreach($mapUnlocated as $index => $item)
                    <li>
                        <button type="button" onclick="selectLocateTarget({{ $index }})"
                                data-locate-index="{{ $index }}"
                                class="w-full text-left px-2 py-1.5 text-xs rounded hover:bg-white">
                            {{ $item['name'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
```

- [ ] **Step 4: 登録モードの JS を足す**

`_map.blade.php` の `<script>` 内、`onAreaMapFailed()` の**前**に追加:

```javascript
var AREA_MAP_UNLOCATED = {{ \Illuminate\Support\Js::from($mapUnlocated) }};
var AREA_MAP_SAVE_BASE = '{{ url('/tenant/area-buildings') }}';
var AREA_MAP_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

var areaLocateMode = false;
var areaLocateIndex = 0;

function toggleLocateMode() {
    areaLocateMode = !areaLocateMode;

    document.getElementById('locate-panel').style.display = areaLocateMode ? 'block' : 'none';
    document.getElementById('area-map-layout').classList.toggle('is-locating', areaLocateMode);
    document.getElementById('btn-locate-mode').textContent = areaLocateMode ? '登録をやめる' : '位置を登録';

    if (areaLocateMode) {
        renderLocateList();
    } else {
        showMessage('');
    }
}

function currentLocateTarget() {
    return AREA_MAP_UNLOCATED[areaLocateIndex] || null;
}

function renderLocateList() {
    var buttons = document.querySelectorAll('#locate-list button[data-locate-index]');
    for (var i = 0; i < buttons.length; i++) {
        var isCurrent = Number(buttons[i].getAttribute('data-locate-index')) === areaLocateIndex;
        buttons[i].style.background = isCurrent ? '#059669' : '';
        buttons[i].style.color = isCurrent ? '#ffffff' : '';
        buttons[i].style.fontWeight = isCurrent ? '700' : '';
    }

    document.getElementById('locate-remaining').textContent = String(
        AREA_MAP_UNLOCATED.filter(function (item) { return !item.done; }).length
    );

    var target = currentLocateTarget();
    showMessage(target ? '「' + target.name + '」の位置を地図でクリックしてください。' : '未登録の棟はありません。');
}

function selectLocateTarget(index) {
    areaLocateIndex = index;
    renderLocateList();
}

function skipLocateTarget() {
    advanceLocateTarget();
}

/** 次の未処理の棟へ。⚠ 末尾まで行ったら先頭へ戻して取りこぼしを拾えるようにする */
function advanceLocateTarget() {
    for (var i = areaLocateIndex + 1; i < AREA_MAP_UNLOCATED.length; i++) {
        if (!AREA_MAP_UNLOCATED[i].done) { areaLocateIndex = i; renderLocateList(); return; }
    }
    for (var j = 0; j < AREA_MAP_UNLOCATED.length; j++) {
        if (!AREA_MAP_UNLOCATED[j].done) { areaLocateIndex = j; renderLocateList(); return; }
    }
    showMessage('未登録の棟はすべて片付きました。');
}

/**
 * クリックした位置を保存する。
 *
 * ⚠ **地図を動かさない。** 隣接する棟が続くので、setCenter / setZoom / fitBounds を
 *   呼ぶと毎回探し直しになる（設計書 §4.3）。
 * ⚠ null 返し方式。`if (!res.ok)` と `if (!data)` を対で置く（AjaxErrorFeedbackTest）。
 */
function saveCoordinate(lat, lng) {
    var target = currentLocateTarget();
    if (!target) { return; }

    showMessage('「' + target.name + '」を保存中...');

    fetch(AREA_MAP_SAVE_BASE + '/' + target.id + '/coordinates', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': AREA_MAP_TOKEN
        },
        body: JSON.stringify({ latitude: lat, longitude: lng })
    })
    .then(function (res) {
        if (!res.ok) {
            return res.json().then(function (err) {
                showMessage('保存に失敗しました: ' + (err.message || res.status), true);
                return null;
            }).catch(function () {
                showMessage('保存に失敗しました（' + res.status + '）。もう一度クリックしてください。', true);
                return null;
            });
        }
        return res.json();
    })
    .then(function (data) {
        if (!data) { return; }

        addAreaMapMarker({
            id: data.id, name: target.name, lat: data.latitude, lng: data.longitude,
            level: 'unknown', rateLabel: '—', floors: '—',
            operating: null, vacant: null, unknown: null, month: '—',
            url: AREA_MAP_SAVE_BASE + '/' + data.id
        });

        target.done = true;
        showMessage('「' + target.name + '」を保存しました。');
        advanceLocateTarget();
    })
    .catch(function () {
        showMessage('保存に失敗しました。通信環境を確認してください。', true);
    });
}
```

`onAreaMapReady()` の末尾（`fitBounds` の後）に地図クリックの配線を足す:

```javascript
    areaMapInstance.addListener('click', function (e) {
        if (!areaLocateMode) { return; }
        saveCoordinate(e.latLng.lat(), e.latLng.lng());
    });
```

- [ ] **Step 5: fetch 分類テストへ登録する**

⚠ これをやらないと `AjaxErrorFeedbackTest::test_every_fetch_view_is_classified` が落ちる（新しい fetch 付きビューはどれかのリストに入っていなければならない。Bug #45 ①）。

`tests/Feature/AjaxErrorFeedbackTest.php` の `VIEWS_NULL_RETURN` の「テナント」ブロックへ追加:

```php
        // テナント
        'tenant/area-buildings/_map.blade.php',
        'tenant/contracts/create.blade.php',
```

- [ ] **Step 6: 通ることを確認する**

Run: `vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingMapTabTest.php tests/Feature/AjaxErrorFeedbackTest.php`
Expected: OK

- [ ] **Step 7: コミット**

```bash
git add resources/views/tenant/area-buildings/_map.blade.php tests
git commit -m "feat(tenant): 地図タブでクリックして位置を登録できるようにする"
```

---

## Task 9: 実ブラウザでの確認（ローカル）

PHP のテストでは地図の振る舞いを守れない（Bug #28 / #32 / #43）。**必ず実際に動かす。**

- [ ] **Step 1: ビルドと確認手順**

```bash
cd /Users/masanori/site/manage && php artisan view:clear && php artisan route:clear && php artisan config:clear
```

- [ ] **Step 2: ローカルで一覧を開き、次を目視で確認する**

1. 既定が「表」タブで、所在地の列が無いこと
2. 「地図」タブに切り替えるとピンが出ること（凡例の色と一致すること）
3. ピンをクリックして吹き出しが出ること
4. 「位置を登録」を押すと左にリストが出て、地図クリックで保存 → 次の棟へ進むこと
5. **保存しても地図の中心とズームが動かないこと**
6. 同じ棟をリストで選び直して置き直せること

- [ ] **Step 3: 横スクロールが出ていないことを DOM で実測する**

⚠ スクリーンショットでは判定できない（Bug #29）。ブラウザのコンソールで:

```javascript
(function () { var m = document.querySelector('main'); return { scrollWidth: m.scrollWidth, clientWidth: m.clientWidth, ok: m.scrollWidth === m.clientWidth }; })()
```

Expected: `ok: true`。**広い幅（1440px）と狭い幅（375px）の両方で測る**（超過幅は定数なので片方だけでは判定できない）。

- [ ] **Step 4: 表タブで地図が読み込まれていないことを実測する**

表タブを開いた状態でコンソール:

```javascript
typeof google
```

Expected: `"undefined"`（`"object"` なら課金方針が壊れている）

- [ ] **Step 5: 結果を記録してコミット**

```bash
git commit --allow-empty -m "chore(tenant): 地図タブのローカル実機確認（横スクロール無し・表タブで Maps 未読込）"
```

---

## Task 10: 変異テストで検出力を実測する

⚠ **「テストが緑」は検証にならない**（Bug #42 / #44 / #45）。

手順は毎回同じ:
1. **先にコミットする**（未コミットのまま変異を当てると `git checkout --` で自分の編集ごと消える）
2. 変異の**前**に `git status --porcelain` が空であることを確認する
3. `git diff --stat` が非空であることで**着弾**を確認する（当たっていない変異を「検出しない」と誤読しない）
4. **落ちた理由の文言まで**突き合わせる（別の機構が落としているのを成功と誤読しない）
5. `git checkout -- <file>` で戻す

- [ ] **Step 1: 次の 7 通りを順に測る**

| # | 変異 | 期待して落ちるテスト |
|---|---|---|
| 1 | `storeCoordinate()` に `->whereNull('latitude')` を足す | `test_existing_coordinates_are_overwritten` |
| 2 | `VacancyRate::BAND_MID` を `20.0` に戻す | `test_level_bands` / `test_vacancy_band_boundaries_are_inclusive_at_25_and_50_percent` |
| 3 | `matchesVacancy()` を `$rate >= 25.0` の直値に戻す | `test_vacancy_filter_reads_the_shared_band_constants` |
| 4 | `index()` の `'mapPins' => $isMap ? ... : []` を常に組み立てる＋`_map` を無条件 include | `test_the_table_tab_is_the_default_and_loads_no_map` |
| 5 | `mapPins()` を `$service->paginateRows(...)` 由来に変える（＝ページング） | `test_the_map_tab_is_not_paginated` |
| 6 | `update()` の `array_key_exists` ガードを外して `?? null` に戻す | `test_update_without_address_keeps_the_existing_address` |
| 7 | `saveCoordinate()` の末尾に `areaMapInstance.setCenter({lat: lat, lng: lng});` を足す | `test_saving_a_pin_does_not_recenter_the_map` |

各変異のコマンド例（#1）:

```bash
git status --porcelain   # 空であること
perl -0pi -e 's/(\$building->update\(\[\n            .latitude.)/\$building->newQuery()->whereNull("latitude")->update([\n            "latitude"/' app/Http/Controllers/Tenant/AreaBuildingController.php
git diff --stat          # 非空であること（着弾確認）
vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingCoordinateStoreTest.php
git checkout -- app/Http/Controllers/Tenant/AreaBuildingController.php
```

⚠ `perl` の置換が 0 箇所だった場合は**変異が当たっていない**。手で編集してから測り直すこと。

- [ ] **Step 2: 結果を表にして設計書へ追記する**

`docs/superpowers/specs/2026-08-19-area-building-map-tab-design.md` の §9 の末尾に「変異テストの実測（YYYY-MM-DD）」として、7 通りの**変異 / 落ちたテスト / 落ちた理由の文言**を記録する。検出できなかったものがあれば、テストを足してから再測する。

- [ ] **Step 3: コミット**

```bash
git add docs/superpowers/specs/2026-08-19-area-building-map-tab-design.md
git commit -m "docs(spec): 地図タブの変異テスト結果を記録する"
```

---

## Task 11: 本番反映

- [ ] **Step 1: 全テストが緑であることを確認する**

Run: `vendor/bin/phpunit`
Expected: OK

- [ ] **Step 2: main repo へ FF マージする**

```bash
cd /Users/masanori/site/manage && git merge --ff-only area-building-map-tab
```

⚠ 新規 PHP クラスは追加していないので `composer dump-autoload` は不要。

- [ ] **Step 3: 本番反映（ユーザーの明示承認を得てから）**

```bash
./deploy.sh
```

⚠ ルートとビューを変えたので `route:cache` / `view:cache` の再生成が要る。`git push` だけでは反映されない（Bug #20）。
⚠ DB 変更は無い。

- [ ] **Step 4: 本番のブラウザで確認する**

1. `/tenant/area-buildings` が表タブで開き、所在地の列が無いこと
2. `?view=map` でピンが出ること（現状 0 件なので**松山市中心にフォールバック**すること）
3. 「位置を登録」で 1 棟置いて保存できること、**リロード後も残っていること**
4. 同じ棟を選び直して置き直せること
5. 表タブで `typeof google` が `"undefined"` であること

- [ ] **Step 5: 完了を記録する**

`docs/BACKLOG.md` の「周辺ビル調査 第1段」の節に、第2段の一部（地図タブと位置登録）を本番反映した旨と日付・コミットを追記してコミットする。

---

## Self-Review

**1. Spec coverage**

| 設計書 | 対応するタスク |
|---|---|
| §3 閾値の実測 | Task 1（定数）/ Task 2（フィルタ） |
| §4.1 タブ | Task 7 Step 5 |
| §4.2 閲覧モード | Task 7 Step 6-7 |
| §4.3 登録モード | Task 8 |
| §4.4 モバイル | Task 7 Step 6（`area-map-layout` の media query）/ Task 9 Step 3（375px 実測） |
| §5.1 ルート | Task 3 |
| §5.2 ピンデータ | Task 7 Step 4（コントローラで組み立て）/ Step 7（`Js::from`） |
| §6.1 所在地を消す | Task 5 |
| §6.2 残すもの | Task 5 Step 1（消しすぎ検出テスト） |
| §6.3 フォームの単純化 | Task 6 |
| §7 課金 | Task 7 Step 1（表タブで地図なし）/ Task 9 Step 4（実測） |
| §8 権限 | Task 3（ルート）/ Task 8 Step 1（トグルの出し分け） |
| §9 テスト方針 | 各タスク ＋ Task 10（変異） |
| §11 本番反映 | Task 11 |

⚠ 設計書に無くプランで足したもの: **Task 4（住所が消える欠陥）**。設計書 §6.1 の「入力欄を削除」を実装すると発火する副作用で、実装中に見つけた。設計書 §6.2 の「DB 列は温存」と整合させるために必要。

**2. Placeholder scan** — 「TBD」「後で」「適切に」の類は無し。

**自己レビューで直した 4 点**（いずれも実測で確認してから修正）:

| # | 見つけた欠陥 | 直し方 |
|---|---|---|
| 1 | `_form.blade.php` の**見出し「所在地マップ」が画面に出る**ので、所在地を消すテストが落ちる | Task 5 Step 6 に見出しの変更を追加 |
| 2 | 地図タブのテストが `<table` の不在で見ており、レイアウト側に表が増えると巻き込まれる | 一覧の表にしか無い `<colgroup` で見る |
| 3 | ページングのテストが `substr_count($html, '"name":"棟')` で数えており、**`Js::from()` の `JSON_HEX_QUOT` と `\uXXXX` で 1 件も一致しない**（常に赤） | `$response->viewData('mapPins')` を数える |
| 4 | テスト文中のタイプミス（`二重описание`） | 削除 |

**3. Type consistency**

- `VacancyRate::level()` は Task 1 で定義し、Task 7 の `mapPins()` から `VacancyRate::LEVEL_UNKNOWN` と対で使う — 一致
- `paginateRows(Collection $rows, Request $request, int $perPage = 20)` は Task 7 Step 3 で定義し、Step 4 で `$service->paginateRows($rows, $request)` と呼ぶ — 一致
- `storeCoordinate`（単数）＝新設 / `storeCoordinates`（複数）＝既存の一括取得。**名前が 1 文字違いなので混同しないこと**
- JS の `showMessage(text, isError)` は Task 7 Step 7 で定義し、Task 8 の `saveCoordinate()` から使う — 一致。⚠ この名前は `AjaxErrorFeedbackTest::SINK` が「ユーザーに見える出力」として認めている語彙なので変えない
- `addAreaMapMarker(pin)` は Task 7 で定義し Task 8 の保存成功時に再利用する。渡すオブジェクトのキー（`id/name/lat/lng/level/rateLabel/floors/operating/vacant/unknown/month/url`）は `mapPins()` の出力と同じ形 — 一致
