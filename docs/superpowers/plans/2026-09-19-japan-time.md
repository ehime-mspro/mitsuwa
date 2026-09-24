# 日時の表示と「今日」を日本時間にそろえる Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 保存された日時（UTC の TIMESTAMP 列）を画面では日本時間で出し、「今日」「今月」「今年度」を日本の暦の日付で決める。アプリの timezone（UTC）・保存・ログは変えない。

**Architecture:** 新しい部品 `App\Support\JapanTime` に `format()`（保存された日時 → 日本時間の文字列）と `today()`（日本の今日を**アプリの timezone の 0:00** で返す）だけを置き、表示 24 か所と「今日」の約 60 か所をこの 2 つへ置き換える。漏れと後戻りは、全件分類の走査テスト 2 本（保存された日時の直接の整形・時計の読み取り）が止める。

**Tech Stack:** Laravel 12 / PHP 8.3 / Carbon 3.11 / PHPUnit（SQLite）

**利用者の判断（2026-09-19）:** 案 (a) を採用（アプリ全体の timezone は UTC のまま。段階0 の決定と `tests/Feature/Ops/ScheduleTest.php::test_application_timezone_stays_utc` はそのまま）。

---

## 前提と作法（全タスク共通）

- 作業場所: `/Users/masanori/site/manage/.claude/worktrees/japan-time`（ブランチ `japan-time`。13.x の `6844b7f6` から）。
  **main repo（`/Users/masanori/site/manage`）で作業しない・`cd` しない。**
- テスト: `cd /Users/masanori/site/manage/.claude/worktrees/japan-time && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
  （基準: `OK (1711 tests, 10534 assertions)`・約 1 分）。1 本だけなら末尾に `--filter <テストのクラス名>`。
- ⚠ `.env`・`.env.*` を読まない・開かない・grep しない（この worktree には `.env.example` がある）。
- ⚠ 各タスクの最後に**全件**を流して緑を確かめてからコミットする。コミットは HEREDOC で、末尾に
  **自分のシステム文脈にある Co-Authored-By の行**を付ける（モデル名を決め打ちしない）:

```bash
git add <files>
git commit -F- <<'EOF'
feat: 〜する

Co-Authored-By: <自分のシステム文脈にある行をそのまま>
EOF
```

- ⚠ `--no-verify` を使わない。`git stash` を使わない。push しない。
- ⚠ ビューでは部品を FQCN（`\App\Support\JapanTime::…`）で呼ぶ。Blade ディレクティブは作らない（docs/RULES.md Bug #30）。
- ⚠ PHP ファイルで部品を使うときは `use App\Support\JapanTime;` を use 文の並びに足す（`App\Support` 名前空間の中の
  `ZealFiscalYear.php` だけは不要）。置き換えで使われなくなった use 文があれば消す（`Carbon` はほとんどの所で他にも使われているので残る）。
- 置き換えは「今」の行が表のとおりであることを確かめてから行う（行番号は 13.x の `6844b7f6` 時点）。

### 置き換えの規則

| 用途 | 今 | これから |
|---|---|---|
| 保存された日時の表示 | `$x->created_at?->format('Y/m/d H:i')` | `\App\Support\JapanTime::format($x->created_at)` |
| 同・日付だけ | `$f->created_at->format('Y/m/d')` | `JapanTime::format($f->created_at, 'Y/m/d')` |
| 日本の今日・今月・今年度 | `now()` `today()` `Carbon::now()` `date('Y')` `CarbonImmutable::today()` | `JapanTime::today()`（不変が要る所は `->toImmutable()`） |
| TIMESTAMP 列への保存・期限・運用 | `now()` など | **変えない** |

⚠ **`JapanTime::today()` は日本時間の 0:00 ではなく、日本の日付の「UTC の 0:00」を返す。** 日本時間の 0:00（UTC では
前日 15:00）で返すと、date キャストの属性（UTC の 0:00）や `Carbon::create(年, 月, 日)`・`createFromFormat('Y-m-d', …)` と
前後を比べたときに 9 時間ずれる（`ZealFiscalYear::isFutureMonth()` が「今月」を「来月」と判定する）。

---

## Task 1: 部品 `JapanTime`

**Files:**
- Create: `app/Support/JapanTime.php`
- Test: `tests/Feature/Support/JapanTimeTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Support/JapanTimeTest.php`:

```php
<?php

namespace Tests\Feature\Support;

use App\Models\Repair;
use App\Support\JapanTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日本時間の部品（docs/RULES.md Bug #61）。
 *
 * ⚠ Feature の TestCase で書く（Laravel を起動する）。素の PHPUnit の TestCase だと既定の時刻帯が
 *   config/app.php でなく php.ini に左右され、走らせるマシンで結果が変わる（Bug #54 ①）。
 * ⚠ 時刻は日本時間の 0:00〜8:59（UTC ではまだ前日）に固定する。昼に固定すると UTC と日本の日付が
 *   同じになり、変換を外しても緑のまま通る。
 */
class JapanTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_app_timezone_is_still_utc(): void
    {
        // この部品は「アプリは UTC」を前提にしている（段階0 の決定。tests/Feature/Ops/ScheduleTest.php と対）
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_format_converts_a_stored_utc_time_to_japan_time(): void
    {
        $stored = Carbon::parse('2026-09-18 17:12:00', 'UTC'); // 日本時間 9/19 2:12

        $this->assertSame('2026/09/19 02:12', JapanTime::format($stored));
        $this->assertSame('09/19 02:12', JapanTime::format($stored, 'm/d H:i'));
        $this->assertSame('2026/09/19', JapanTime::format($stored, 'Y/m/d'));
    }

    public function test_format_accepts_immutable_values_and_leaves_the_argument_alone(): void
    {
        $stored = Carbon::parse('2026-04-30 15:30:00', 'UTC');

        $this->assertSame('2026/05/01 00:30', JapanTime::format($stored->toImmutable()));
        $this->assertSame('2026/05/01 00:30', JapanTime::format($stored));
        $this->assertSame('2026-04-30 15:30:00', $stored->toDateTimeString(), '引数の時刻が書き換わっている');
        $this->assertSame('UTC', $stored->timezoneName, '引数の時刻帯が書き換わっている');
    }

    public function test_format_returns_null_for_null(): void
    {
        $this->assertNull(JapanTime::format(null));
    }

    public function test_today_is_the_japanese_date_before_nine_in_the_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30

        $today = JapanTime::today();

        $this->assertSame('2026-05-01', $today->toDateString());
        $this->assertSame(2026, $today->year);
        $this->assertSame(5, $today->month);
    }

    public function test_today_turns_over_exactly_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 14:59:59', 'UTC')); // 日本時間 4/30 23:59:59
        $this->assertSame('2026-04-30', JapanTime::today()->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-04-30 15:00:00', 'UTC')); // 日本時間 5/1 0:00
        $this->assertSame('2026-05-01', JapanTime::today()->toDateString());
    }

    public function test_today_is_midnight_in_the_app_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $today = JapanTime::today();

        // ⚠ 日本時間の 0:00（UTC の 4/30 15:00）で返すと、date キャストの属性や Carbon::create() と 9 時間ずれる
        $this->assertSame('2026-05-01 00:00:00', $today->toDateTimeString());
        $this->assertSame('UTC', $today->timezoneName);
        $this->assertTrue($today->equalTo(Carbon::create(2026, 5, 1)));
    }

    public function test_today_equals_a_date_cast_attribute_of_the_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $repair = (new Repair())->forceFill(['started_at' => '2026-05-01']); // date キャスト

        $this->assertTrue(JapanTime::today()->equalTo($repair->started_at));
        $this->assertFalse(JapanTime::today()->greaterThan($repair->started_at));
    }

    public function test_today_returns_a_new_mutable_instance_each_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $first = JapanTime::today();
        $first->addDay();

        $this->assertInstanceOf(Carbon::class, $first);
        $this->assertSame('2026-05-01', JapanTime::today()->toDateString(), '前の呼び出しの変更が次に漏れている');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter JapanTimeTest`
Expected: FAIL（`Class "App\Support\JapanTime" not found`。`test_the_app_timezone_is_still_utc` だけは通る）

- [ ] **Step 3: 部品を書く**

`app/Support/JapanTime.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 日本時間（Asia/Tokyo）の扱いを 1 か所にまとめる（docs/RULES.md Bug #61）。
 *
 * アプリの timezone は UTC のまま（config/app.php・段階0 の決定。tests/Feature/Ops/ScheduleTest.php が固定）。
 * 保存・ログ・瞬間の比較は UTC で行い、**利用者に見せる日時**と**日本の今日**だけをここで作る。
 *
 * ⚠ 保存された日時（TIMESTAMP 列。UTC で入っている）を見せるときは format() を通す。`->format()` を
 *   直接呼ぶと 9 時間ずれる（走査テスト StoredTimestampDisplayScanTest が止める）。
 * ⚠ 「今日」「今月」「今年度」を now() / today() / date() で作らない。日本時間の 0:00〜8:59 は UTC では
 *   まだ前日（走査テスト ClockReadScanTest が止める）。
 */
final class JapanTime
{
    public const ZONE = 'Asia/Tokyo';

    /**
     * 保存された日時（UTC）を日本時間で整形する。null は null（呼び出し側の `?? '—'` がそのまま使える）。
     */
    public static function format(?DateTimeInterface $at, string $format = 'Y/m/d H:i'): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(self::ZONE)->format($format);
    }

    /**
     * 日本の今日（暦の日付）を、**アプリの timezone の 0:00** として返す（毎回新しい可変のインスタンス）。
     *
     * ⚠ 日本時間の 0:00（UTC では前日の 15:00）で返さない。date キャストの属性（UTC の 0:00）や
     *   Carbon::create(年, 月, 日)・createFromFormat('Y-m-d', …) と前後を比べると 9 時間ずれ、
     *   たとえば ZealFiscalYear::isFutureMonth() が「今月」を「来月」と判定する。
     *   この形なら ->year / ->month / ->format('Y-m-d') / ->startOfMonth() も、date 属性との比較もそのまま正しい。
     * ⚠ TIMESTAMP 列（UTC の瞬間）へ保存する・TIMESTAMP 列と比べる用途には使わない（それは now() のまま）。
     */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::ZONE)->toDateString());
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter JapanTimeTest`
Expected: PASS（9 tests）

- [ ] **Step 5: 全件 → コミット**

全件が緑（1720 tests 前後）であることを確かめてから:

```bash
git add app/Support/JapanTime.php tests/Feature/Support/JapanTimeTest.php
git commit -F- <<'EOF'
feat: 日本時間の表示と日本の今日を作る部品を足す

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 2: 保存された日時の表示（24 か所）

**Files:**
- Modify（ビュー 9）: `resources/views/tenant/properties/show.blade.php` / `resources/views/realestate/contracts/show.blade.php` /
  `resources/views/admin/users/index.blade.php` / `resources/views/housing/contracts/show-building.blade.php` /
  `resources/views/housing/contracts/show-custom-order.blade.php` / `resources/views/housing/contracts/edit-custom-order.blade.php` /
  `resources/views/housing/custom-orders/show.blade.php` / `resources/views/housing/properties/show.blade.php` /
  `resources/views/components/attachment-section.blade.php`
- Modify（コントローラ 4）: `app/Http/Controllers/AttachmentController.php` / `app/Http/Controllers/Housing/PropertyController.php` /
  `app/Http/Controllers/Housing/CustomOrderController.php` / `app/Http/Controllers/RealEstate/ProjectController.php`
- Test: `tests/Feature/Housing/HousingTimestampDisplayTest.php` / `tests/Feature/Admin/UserLastLoginDisplayTest.php` /
  `tests/Feature/AttachmentTimestampDisplayTest.php`

- [ ] **Step 1: 失敗するテストを 3 本書く**

`tests/Feature/Housing/HousingTimestampDisplayTest.php`:

```php
<?php

namespace Tests\Feature\Housing;

use App\Models\HsPropertyFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 建売物件の詳細: 登録・更新の日時（Blade）とファイルの登録日（JSON で JS へ渡す日付だけの値）を日本時間で出す（Bug #61）。
 */
class HousingTimestampDisplayTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registered_and_updated_times_are_shown_in_japan_time(): void
    {
        $manager = $this->manager(['housing']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12
        $property = $this->makeParent('property', ['created_by' => $manager->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59
        $property->update(['updated_by' => $manager->id, 'property_name' => '余戸南 3号地（改）']);

        $html = $this->actingAs($manager)->get(route('housing.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('2026/09/19 02:12', $html, '登録の日時が日本時間になっていない');
        $this->assertStringContainsString('2026/09/19 08:59', $html, '更新の日時が日本時間になっていない');
        $this->assertStringNotContainsString('2026/09/18 17:12', $html, '登録の日時が UTC のまま出ている');
        $this->assertStringNotContainsString('2026/09/18 23:59', $html, '更新の日時が UTC のまま出ている');
    }

    public function test_file_upload_dates_are_japanese_dates(): void
    {
        $manager = $this->manager(['housing']);
        $property = $this->makeParent('property', ['created_by' => $manager->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 16:30:00', 'UTC')); // 日本時間 9/19 1:30
        HsPropertyFile::create([
            'property_id' => $property->id,
            'category'    => 'other',
            'file_name'   => 'plan.pdf',
            'file_path'   => 'housing/properties/' . $property->id . '/plan.pdf',
            'file_size'   => 10,
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $manager->id,
        ]);

        $files = $this->actingAs($manager)->get(route('housing.properties.show', $property))
            ->assertOk()
            ->viewData('filesByCategory');

        $this->assertSame('2026/09/19', $files['other'][0]['created_at'], 'ファイルの登録日が日本の日付になっていない');
    }
}
```

`tests/Feature/Admin/UserLastLoginDisplayTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 基幹の利用者一覧の「最終ログイン」（m/d H:i）を日本時間で出す（Bug #61） */
class UserLastLoginDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_login_is_shown_in_japan_time(): void
    {
        $exec = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
        User::factory()->create([
            'name'          => '最終 ログイン',
            'last_login_at' => Carbon::parse('2026-09-18 17:12:00', 'UTC'), // 日本時間 9/19 2:12
        ]);

        $html = $this->actingAs($exec)->get(route('admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('09/19 02:12', $html, '最終ログインが日本時間になっていない');
        $this->assertStringNotContainsString('09/18 17:12', $html, '最終ログインが UTC のまま出ている');
    }
}
```

`tests/Feature/AttachmentTimestampDisplayTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 添付の登録・削除の日時を日本時間で出す（Bug #61）。
 * 共通部品 components/attachment-section は 7 画面が @include している（一覧は @json で JS へ渡す）。
 * 準備は AttachmentDeliveryTest と同じ（attachable_type に Contract::class。親の行は作らない）。
 */
class AttachmentTimestampDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DepartmentSeeder::class);

        $this->staff = User::factory()->create(['role' => UserRole::Staff->value]);
        $this->staff->departments()->attach(Department::where('code', 'tenant')->value('id'));
        $this->actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAttachment(string $fileName): Attachment
    {
        $path = 'attachments/contracts/1/' . $fileName;
        Storage::disk('public')->put($path, 'FAKEBYTES');

        return Attachment::create([
            'attachable_type' => Contract::class,
            'attachable_id'   => 1,
            'file_name'       => $fileName,
            'file_path'       => $path,
            'file_size'       => 9,
            'mime_type'       => 'application/pdf',
            'uploaded_by'     => $this->staff->id,
        ]);
    }

    public function test_the_section_shows_upload_and_deletion_times_in_japan_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12
        $live = $this->makeAttachment('live.pdf');
        $gone = $this->makeAttachment('gone.pdf');

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59
        $gone->delete();

        $html = view('components.attachment-section', [
            'attachableType'     => 'contracts',
            'attachableId'       => 1,
            'attachments'        => collect([$live->fresh()]),
            'deletedAttachments' => Attachment::onlyTrashed()->get(),
        ])->render();

        // @json は "/" を "\/" に書く
        $this->assertStringContainsString('"uploaded_at":"2026\/09\/19 02:12"', $html, '登録の日時が日本時間になっていない');
        $this->assertStringContainsString('"deleted_at":"2026\/09\/19 08:59"', $html, '削除の日時が日本時間になっていない');
        $this->assertStringNotContainsString('2026\/09\/18', $html, 'UTC の日付が残っている');
    }

    public function test_the_delete_response_carries_the_japan_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC'));
        $attachment = $this->makeAttachment('x.pdf');

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59

        $this->deleteJson(route('attachments.destroy', $attachment))
            ->assertOk()
            ->assertJsonPath('deleted.deleted_at', '2026/09/19 08:59');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter 'HousingTimestampDisplayTest|UserLastLoginDisplayTest|AttachmentTimestampDisplayTest'`
Expected: 5 本とも FAIL（どれも UTC の `2026/09/18 …` / `09/18 17:12` が出ている）

- [ ] **Step 3: 24 か所を置き換える**

ビュー（`{{ }}` の中だけを変える。前後のタグ・クラスはそのまま）:

| ファイル:行 | 今 | これから |
|---|---|---|
| tenant/properties/show.blade.php:532 | `{{ $log->changed_at->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($log->changed_at) }}` |
| realestate/contracts/show.blade.php:294 | `{{ $contract->created_at?->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($contract->created_at) }}` |
| realestate/contracts/show.blade.php:298 | `{{ $contract->updated_at?->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($contract->updated_at) }}` |
| admin/users/index.blade.php:138 | `{{ $u->last_login_at ? $u->last_login_at->format('m/d H:i') : '—' }}` | `{{ \App\Support\JapanTime::format($u->last_login_at, 'm/d H:i') ?? '—' }}` |
| housing/contracts/show-building.blade.php:349 | `{{ $contract->created_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($contract->created_at) ?? '—' }}` |
| housing/contracts/show-building.blade.php:354 | `{{ $contract->updated_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($contract->updated_at) ?? '—' }}` |
| housing/contracts/show-custom-order.blade.php:388 | `{{ $hsCustomOrder->created_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($hsCustomOrder->created_at) ?? '—' }}` |
| housing/contracts/show-custom-order.blade.php:393 | `{{ $hsCustomOrder->updated_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($hsCustomOrder->updated_at) ?? '—' }}` |
| housing/contracts/edit-custom-order.blade.php:682 | `{{ $hsCustomOrder->created_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($hsCustomOrder->created_at) ?? '—' }}` |
| housing/contracts/edit-custom-order.blade.php:686 | `{{ $hsCustomOrder->updated_at?->format('Y/m/d H:i') ?? '—' }}` | `{{ \App\Support\JapanTime::format($hsCustomOrder->updated_at) ?? '—' }}` |
| housing/custom-orders/show.blade.php:319 | `{{ $o->created_at->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($o->created_at) }}` |
| housing/custom-orders/show.blade.php:321 | `{{ $o->updated_at->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($o->updated_at) }}` |
| housing/properties/show.blade.php:311 | `{{ $property->created_at->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($property->created_at) }}` |
| housing/properties/show.blade.php:313 | `{{ $property->updated_at->format('Y/m/d H:i') }}` | `{{ \App\Support\JapanTime::format($property->updated_at) }}` |
| components/attachment-section.blade.php:27 | `'uploaded_at' => $a->created_at->format('Y/m/d H:i'),` | `'uploaded_at' => \App\Support\JapanTime::format($a->created_at),` |
| components/attachment-section.blade.php:38 | `'deleted_at' => $a->deleted_at->format('Y/m/d H:i'),` | `'deleted_at' => \App\Support\JapanTime::format($a->deleted_at),` |

コントローラ（4 本とも `use App\Support\JapanTime;` を足す）:

| ファイル:行 | 今 | これから |
|---|---|---|
| app/Http/Controllers/AttachmentController.php:99 | `'uploaded_at' => $attachment->created_at->format('Y/m/d H:i'),` | `'uploaded_at' => JapanTime::format($attachment->created_at),` |
| app/Http/Controllers/AttachmentController.php:169 | `'deleted_at' => $attachment->deleted_at->format('Y/m/d H:i'),` | `'deleted_at' => JapanTime::format($attachment->deleted_at),` |
| app/Http/Controllers/Housing/PropertyController.php:150 | `'created_at'  => $file->created_at->format('Y/m/d'),` | `'created_at'  => JapanTime::format($file->created_at, 'Y/m/d'),` |
| app/Http/Controllers/Housing/PropertyController.php:327 | `'created_at'  => $record->created_at->format('Y/m/d'),` | `'created_at'  => JapanTime::format($record->created_at, 'Y/m/d'),` |
| app/Http/Controllers/Housing/CustomOrderController.php:137 | `'created_at'  => $file->created_at->format('Y/m/d'),` | `'created_at'  => JapanTime::format($file->created_at, 'Y/m/d'),` |
| app/Http/Controllers/Housing/CustomOrderController.php:294 | `'created_at'  => $record->created_at->format('Y/m/d'),` | `'created_at'  => JapanTime::format($record->created_at, 'Y/m/d'),` |
| app/Http/Controllers/RealEstate/ProjectController.php:404 | `'created_at'  => $d->created_at->format('Y/m/d'),` | `'created_at'  => JapanTime::format($d->created_at, 'Y/m/d'),` |
| app/Http/Controllers/RealEstate/ProjectController.php:720 | `'created_at'    => $drawing->created_at->format('Y/m/d'),` | `'created_at'    => JapanTime::format($drawing->created_at, 'Y/m/d'),` |

置き換えたあと、残りが無いことを確かめる（0 行になること）:

```bash
rg -n -e "->(created_at|updated_at|deleted_at|last_login_at|changed_at|email_verified_at|logged_in_at)\??->format\(" app resources/views
```

- [ ] **Step 4: 通ることを確かめる**

Run: 上と同じ `--filter`。Expected: PASS（5 tests）

- [ ] **Step 5: 全件 → コミット**

```bash
git add app/Http/Controllers/AttachmentController.php app/Http/Controllers/Housing/PropertyController.php \
  app/Http/Controllers/Housing/CustomOrderController.php app/Http/Controllers/RealEstate/ProjectController.php \
  resources/views/tenant/properties/show.blade.php resources/views/realestate/contracts/show.blade.php \
  resources/views/admin/users/index.blade.php resources/views/housing/contracts/show-building.blade.php \
  resources/views/housing/contracts/show-custom-order.blade.php resources/views/housing/contracts/edit-custom-order.blade.php \
  resources/views/housing/custom-orders/show.blade.php resources/views/housing/properties/show.blade.php \
  resources/views/components/attachment-section.blade.php \
  tests/Feature/Housing/HousingTimestampDisplayTest.php tests/Feature/Admin/UserLastLoginDisplayTest.php \
  tests/Feature/AttachmentTimestampDisplayTest.php
git commit -F- <<'EOF'
fix: 保存された日時を日本時間で表示する

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 3: 「今日」の表示・既定値・保存（A・B・C）

**Files:**
- Modify（ビュー 18）: 下の表
- Modify（PHP）: `app/Http/Controllers/Zeal/DashboardController.php` / `app/Http/Controllers/DashboardController.php`（105・128 行だけ）/
  `app/Http/Controllers/Tenant/InquiryController.php` / `app/Http/Controllers/Tenant/ContractController.php` /
  `app/Http/Controllers/Tenant/InvestmentController.php` / `app/Http/Controllers/Admin/TenantImportController.php`（813・1307 行）/
  `app/Http/Controllers/TransactionController.php`（26 行だけ）/ `app/Models/Buyer.php`
- Modify（既存テスト）: `tests/Feature/Tenant/AreaBuildingImportTest.php:1187`
- Test: `tests/Feature/Tenant/InquiryJapanDateTest.php` / `tests/Feature/Mansion/DashboardJapanDateTest.php`

- [ ] **Step 1: 失敗するテストを 2 本書く**

`tests/Feature/Tenant/InquiryJapanDateTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 問合せ: 登録画面の既定の問合せ日・採番の年・状態変更で自動で付く対応履歴の日付を、日本の日付で決める（Bug #61）。
 * 時刻は日本時間の 2027/1/1 0:30（UTC ではまだ 2026/12/31）。
 */
class InquiryJapanDateTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_YEARS_MORNING_UTC = '2026-12-31 15:30:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
    }

    public function test_the_create_form_uses_the_japanese_date_on_new_years_morning(): void
    {
        $exec = $this->executive();
        Carbon::setTestNow(Carbon::parse(self::NEW_YEARS_MORNING_UTC, 'UTC'));

        $html = $this->actingAs($exec)->get(route('tenant.inquiries.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="inquiry_date" value="2027-01-01"', $html, '問合せ日の既定が日本の今日でない');
        $this->assertStringContainsString('value="INQ-2027-001"', $html, '問合せ番号の年が日本の年でない');
        $this->assertStringNotContainsString('INQ-2026-', $html);
    }

    public function test_the_automatic_history_of_a_status_change_is_dated_in_japan(): void
    {
        $exec = $this->executive();
        $property = Property::create([
            'code'          => 'PROP-JT-001',
            'name'          => '日付ビル',
            'property_type' => 'tenant',
            'department'    => 'tenant',
            'address'       => '愛媛県松山市本町1-1',
        ]);
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-JT-001',
            'property_id'    => $property->id,
            'status'         => 'follow',
            'contact_name'   => '日付 太郎',
            'inquiry_date'   => '2026-12-01',
            'assigned_to'    => $exec->id,
        ]);

        Carbon::setTestNow(Carbon::parse(self::NEW_YEARS_MORNING_UTC, 'UTC'));
        $this->actingAs($exec)
            ->patch(route('tenant.inquiries.updateStatus', $inquiry), ['status' => 'on_hold'])
            ->assertRedirect();

        $history = InquiryHistory::where('inquiry_id', $inquiry->id)->latest('id')->firstOrFail();
        $this->assertSame('2027-01-01', $history->action_date->toDateString(), '自動の対応履歴の日付が日本の日付でない');
    }
}
```

`tests/Feature/Mansion/DashboardJapanDateTest.php`:

```php
<?php

namespace Tests\Feature\Mansion;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesMansionSchema;
use Tests\TestCase;

/** 賃貸マンションのダッシュボードの「〇年〇月〇日 時点」を日本の日付で出す（Bug #61） */
class DashboardJapanDateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->seed(DepartmentSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_snapshot_date_is_the_japanese_date(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'mansion')->value('id'));

        Carbon::setTestNow(Carbon::parse('2026-09-18 16:00:00', 'UTC')); // 日本時間 9/19 1:00

        $this->actingAs($user)->get(route('mansion.dashboard'))
            ->assertOk()
            ->assertSee('2026年9月19日 時点のスナップショット')
            ->assertDontSee('2026年9月18日 時点のスナップショット');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter 'InquiryJapanDateTest|DashboardJapanDateTest'`
Expected: 3 本とも FAIL（`2026-12-31` / `INQ-2026-001` / `2026年9月18日`）

- [ ] **Step 3: 置き換える**

ビュー:

| ファイル:行 | 今 | これから |
|---|---|---|
| mansion/dashboard.blade.php:211 | `{{ now()->format('Y年n月j日') }}` | `{{ \App\Support\JapanTime::today()->format('Y年n月j日') }}` |
| zeal/simulations/show.blade.php:32 | `$today = now()->format('Y-m-d');` | `$today = \App\Support\JapanTime::today()->format('Y-m-d');` |
| zeal/simulations/_comparison_summary.blade.php:51 | `{{ now()->format('Y-m-d') }}` | `{{ \App\Support\JapanTime::today()->format('Y-m-d') }}` |
| zeal/inquiries/index.blade.php:93 | `{{ \Carbon\Carbon::createFromFormat('Y-m', $m)->format('Y年n月') }}` | `{{ \Carbon\Carbon::createFromFormat('Y-m-d', $m . '-01')->format('Y年n月') }}` |
| tenant/inquiries/create.blade.php:123 | `old('inquiry_date', now()->format('Y-m-d'))` | `old('inquiry_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| tenant/inquiries/show.blade.php:65 | `old('action_date', now()->format('Y-m-d'))` | `old('action_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| tenant/contracts/create.blade.php:190 | `old('contract_date', date('Y-m-d'))` | `old('contract_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| tenant/units/revise.blade.php:126 | `old('revision_date', now()->format('Y-m-d'))` | `old('revision_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| realestate/contracts/create.blade.php:168 | `old('contract_date', date('Y-m-d'))` | `old('contract_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| realestate/contracts/show.blade.php:313 | `old('contract_date', date('Y-m-d'))` | `old('contract_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| buyers/_form.blade.php:18 と :51 | `: date('Y-m-d'))` | `: \App\Support\JapanTime::today()->format('Y-m-d'))` |
| buyers/surveys/create.blade.php:28 | `old('survey_date', date('Y-m-d'))` | `old('survey_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| housing/contracts/_buyer-select.blade.php:165 | `acquired_date: '{{ now()->format('Y-m-d') }}',` | `acquired_date: '{{ \App\Support\JapanTime::today()->format('Y-m-d') }}',` |
| mansion/contracts/terminate.blade.php:26 | `old('move_out_date', now()->format('Y-m-d'))` | `old('move_out_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| mansion/contracts/revise.blade.php:26 | `old('revision_date', now()->format('Y-m-d'))` | `old('revision_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| mansion/parking-contracts/terminate.blade.php:20 | `old('end_date', now()->format('Y-m-d'))` | `old('end_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| mansion/parking-contracts/revise.blade.php:24 | `old('revision_date', now()->format('Y-m-d'))` | `old('revision_date', \App\Support\JapanTime::today()->format('Y-m-d'))` |
| tenant/area-buildings/import.blade.php:307 | `surveyedMonth: '{{ now()->format('Y-m') }}',` | `surveyedMonth: '{{ \App\Support\JapanTime::today()->format('Y-m') }}',` |
| admin/master/zeal-simulation-categories/_form.blade.php:112 | `\Carbon\Carbon::now()->addMonth()->format('Y-m')` | `\App\Support\JapanTime::today()->addMonth()->format('Y-m')` |

⚠ `zeal/inquiries/index.blade.php:93` は時計を読む形（`createFromFormat('Y-m')` は無い「日」を今日から補うので、月末に翌月の名前になる）を
やめるだけ。⚠ 月の足し引きの月末の溢れ（`addMonth()` など）は**直さない**（範囲外。記録に書く）。

PHP:

| ファイル:行 | 今 | これから |
|---|---|---|
| app/Http/Controllers/Zeal/DashboardController.php:38 | `$now          = Carbon::now();` | `$now          = JapanTime::today();` |
| app/Http/Controllers/DashboardController.php:105 | `$previousMonthLabel = now()->subMonth()->month . '月実績';` | `$previousMonthLabel = JapanTime::today()->subMonth()->month . '月実績';` |
| app/Http/Controllers/DashboardController.php:128 | `$now      = now();` | `$now      = JapanTime::today();` |
| app/Http/Controllers/Tenant/InquiryController.php:412 | `'action_date' => now()->toDateString(),` | `'action_date' => JapanTime::today()->toDateString(),` |
| app/Http/Controllers/Tenant/InquiryController.php:442 | `$year = date('Y');` | `$year = JapanTime::today()->year;` |
| app/Http/Controllers/Tenant/ContractController.php:643 | `'action_date' => now()->toDateString(),` | `'action_date' => JapanTime::today()->toDateString(),` |
| app/Http/Controllers/Tenant/ContractController.php:737 | `$year = now()->year;` | `$year = JapanTime::today()->year;` |
| app/Http/Controllers/Tenant/ContractController.php:870 | `'action_date' => now()->toDateString(),` | `'action_date' => JapanTime::today()->toDateString(),` |
| app/Http/Controllers/Tenant/InvestmentController.php:370 | `$year = date('Y');` | `$year = JapanTime::today()->year;` |
| app/Http/Controllers/Admin/TenantImportController.php:813 | `$year = now()->year;` | `$year = JapanTime::today()->year;` |
| app/Http/Controllers/Admin/TenantImportController.php:1307 | `(int) now()->year` | `JapanTime::today()->year` |
| app/Http/Controllers/TransactionController.php:26 | `now()->format('Y-m')` | `JapanTime::today()->format('Y-m')` |
| app/Models/Buyer.php:218 | `$acquiredDate ?: now()->toDateString(),` | `$acquiredDate ?: JapanTime::today()->toDateString(),` |

⚠ `date('Y')` は文字列、`->year` は整数だが、使い道はどれも `"INQ-{$year}-"` のような埋め込みなので結果の文字列は同じ。
⚠ `DashboardController` は 105・128 行だけ（残りは Task 4）。`TransactionController` はルートの無い死にコードだが、
走査テストの対象なので時計の元だけ替える（消さない）。

既存テスト `tests/Feature/Tenant/AreaBuildingImportTest.php:1187`（直さないと日本時間の 1 日 0:00〜8:59 にだけ落ちる）:

```php
            "surveyedMonth: '" . \App\Support\JapanTime::today()->format('Y-m') . "'",
```

- [ ] **Step 4: 通ることを確かめる**

Run: 上と同じ `--filter`。Expected: PASS（3 tests）。`--filter AreaBuildingImportTest` も緑。

- [ ] **Step 5: 全件 → コミット**

```bash
git add -A resources/views app tests/Feature/Tenant/InquiryJapanDateTest.php tests/Feature/Mansion/DashboardJapanDateTest.php \
  tests/Feature/Tenant/AreaBuildingImportTest.php
git status --porcelain   # 表の外のファイルが混ざっていないこと
git commit -F- <<'EOF'
fix: 表示・既定値・保存の「今日」を日本の日付にする

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 4: 判定・集計（D）

**Files:**
- Modify: `app/Support/ZealFiscalYear.php` / `app/Http/Controllers/DashboardController.php`（195・204・757・854 行）/
  `app/Http/Controllers/RealEstate/ReContractController.php` / `app/Http/Controllers/Housing/HsContractListController.php` /
  `app/Http/Controllers/Housing/HousingDashboardController.php` / `app/Http/Controllers/Dad/ProjectController.php` /
  `app/Http/Controllers/Dad/EmployeeController.php` / `app/Http/Controllers/Zeal/InquiryController.php` /
  `app/Http/Controllers/Zeal/SimulationController.php`（768〜775 行）/ `app/Http/Controllers/Admin/TenantImportController.php`（970 行）/
  `app/Http/Controllers/TransactionController.php`（65・258 行）/ `app/Services/ScheduleCardService.php` /
  `app/Services/ScheduleBoardService.php` / `app/Services/Tenant/RentalIncomeService.php` /
  `app/Services/Tenant/ContractAnalysisService.php` / `app/Models/Investment.php` / `app/Models/ZealPlan.php` / `app/Models/ZealMember.php` /
  `resources/views/mansion/contracts/index.blade.php` / `resources/views/mansion/parking-contracts/index.blade.php`
- Modify（既存テスト）: `tests/Feature/DashboardControllerTest.php:218` と `:238`
- Test: `tests/Feature/JapanBusinessDayTest.php` / `tests/Feature/Schedule/ScheduleTodayJapanTimeTest.php`

- [ ] **Step 1: 失敗するテストを 2 本書く**

`tests/Feature/JapanBusinessDayTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\Dad\ProjectController as DadProjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Http\Controllers\Housing\HsContractListController;
use App\Http\Controllers\RealEstate\ReContractController;
use App\Models\ZealMember;
use App\Models\ZealPlan;
use App\Support\ZealFiscalYear;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 年度・当月・年齢・キャンペーン期間を日本の日付で決める（Bug #61）。
 * 時刻は日本時間の 0:00〜8:59（UTC ではまだ前日）に固定する。
 */
class JapanBusinessDayTest extends TestCase
{
    /** 5 月始まりの年度（同じ計算が 5 つのコントローラに複製されている。まとめない） */
    private const MAY_FISCAL_YEAR_METHODS = [
        [DashboardController::class, 'getCurrentFiscalYear'],
        [ReContractController::class, 'getCurrentFiscalYear'],
        [HsContractListController::class, 'getCurrentFiscalYear'],
        [HousingDashboardController::class, 'getCurrentFiscalYear'],
        [DadProjectController::class, 'currentFiscalYear'],
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invokePrivate(string $class, string $method): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(app($class));
    }

    public function test_the_may_fiscal_year_turns_over_at_japan_midnight(): void
    {
        foreach (self::MAY_FISCAL_YEAR_METHODS as [$class, $method]) {
            Carbon::setTestNow(Carbon::parse('2026-04-30 14:59:00', 'UTC')); // 日本時間 4/30 23:59
            $this->assertSame(2025, $this->invokePrivate($class, $method), "{$class}::{$method}: 4/30 はまだ前の年度");

            Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
            $this->assertSame(2026, $this->invokePrivate($class, $method), "{$class}::{$method}: 5/1 の朝は新しい年度");
        }
    }

    public function test_the_dashboard_half_turns_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
        $this->assertSame('h1', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));

        Carbon::setTestNow(Carbon::parse('2026-10-31 15:30:00', 'UTC')); // 日本時間 11/1 0:30
        $this->assertSame('h2', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));
    }

    public function test_zeal_months_turn_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 14:59:00', 'UTC')); // 日本時間 5/31 23:59
        $this->assertSame(2025, ZealFiscalYear::current());
        $this->assertSame('2026-05', ZealFiscalYear::currentMonthYm());

        Carbon::setTestNow(Carbon::parse('2026-05-31 15:30:00', 'UTC')); // 日本時間 6/1 0:30
        $this->assertSame(2026, ZealFiscalYear::current(), '6/1 の朝は新しい年度');
        $this->assertSame('2026-06', ZealFiscalYear::currentMonthYm(), '6/1 の朝は 6 月が当月');
        $this->assertTrue(ZealFiscalYear::isPastMonth('2026-05'), '5 月は締まった月');
        $this->assertTrue(ZealFiscalYear::isCurrentMonth('2026-06'));
        // ⚠ JapanTime::today() が日本時間の 0:00（UTC の前日 15:00）を返すと、ここで当月が「来月」と判定される
        $this->assertFalse(ZealFiscalYear::isFutureMonth('2026-06'), '当月が来月と判定されている（today() が日本時間の 0:00 を返している）');
        $this->assertTrue(ZealFiscalYear::isFutureMonth('2026-07'));
    }

    public function test_a_zeal_members_age_goes_up_at_japan_midnight_on_the_birthday(): void
    {
        $member = (new ZealMember())->forceFill(['birthday' => '1990-09-19']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 14:59:00', 'UTC')); // 日本時間 9/18 23:59
        $this->assertSame(35, $member->age());

        Carbon::setTestNow(Carbon::parse('2026-09-18 15:30:00', 'UTC')); // 日本時間 9/19 0:30（誕生日）
        $this->assertSame(36, $member->age(), '誕生日の朝なのに年齢が上がっていない');
    }

    /**
     * ⚠ 振る舞いが変わる唯一の箇所: 以前は now()（その日の途中の瞬間）を終了日の 0:00 と比べていたため、
     *   終了日の当日（UTC で 0:00 を過ぎた時点＝日本時間の 9:00 以降）が対象外だった。日付で比べるので終了日も適用中になる。
     */
    public function test_a_campaign_runs_from_japan_midnight_of_the_start_day_through_the_end_day(): void
    {
        $plan = (new ZealPlan())->forceFill([
            'campaign_price_excl' => 5000,
            'campaign_starts_on'  => '2026-09-01',
            'campaign_ends_on'    => '2026-09-30',
        ]);

        $cases = [
            ['2026-08-31 14:59:00', false, '開始日の前日（日本時間 8/31 23:59）'],
            ['2026-08-31 15:00:00', true,  '開始日の 0:00（日本時間 9/1 0:00）'],
            ['2026-09-30 14:59:00', true,  '終了日の 23:59（日本時間 9/30 23:59）'],
            ['2026-09-30 15:00:00', false, '終了日の翌日の 0:00（日本時間 10/1 0:00）'],
        ];
        foreach ($cases as [$utc, $expected, $label]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->assertSame($expected, $plan->isCampaignActive(), $label);
        }
    }
}
```

`tests/Feature/Schedule/ScheduleTodayJapanTimeTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;

/** 工程表の「今日」（状態・遅延・今日の線）を日本の日付で決める（Bug #61） */
class ScheduleTodayJapanTimeTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_schedule_card_uses_the_japanese_today(): void
    {
        $property = $this->makeParent('property');
        Carbon::setTestNow(Carbon::parse('2026-08-31 15:30:00', 'UTC')); // 日本時間 9/1 0:30

        $card = app(ScheduleCardService::class)->build($property);

        $this->assertInstanceOf(CarbonImmutable::class, $card['today']);
        $this->assertSame('2026-09-01', $card['today']->toDateString(), '工程表の今日が日本の日付でない');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter 'JapanBusinessDayTest|ScheduleTodayJapanTimeTest'`
Expected: FAIL（5 月始まりの年度・ZEAL の当月・年齢・キャンペーンの 2 か所・工程表の今日）。
`test_the_dashboard_half_turns_over_at_japan_midnight` も FAIL（5/1 0:30 の日本時間で `h2` のまま）。

- [ ] **Step 3: 置き換える**

| ファイル:行 | 今 | これから |
|---|---|---|
| app/Support/ZealFiscalYear.php:31 | `$now = Carbon::now();` | `$now = JapanTime::today();` |
| app/Support/ZealFiscalYear.php:98 | `return Carbon::now()->format('Y-m');` | `return JapanTime::today()->format('Y-m');` |
| app/Support/ZealFiscalYear.php:108 | `$currentStart = Carbon::now()->startOfMonth();` | `$currentStart = JapanTime::today()->startOfMonth();` |
| app/Support/ZealFiscalYear.php:126 | `$currentStart = Carbon::now()->startOfMonth();` | `$currentStart = JapanTime::today()->startOfMonth();` |
| app/Http/Controllers/DashboardController.php:195 | `$now = now();` | `$now = JapanTime::today();` |
| app/Http/Controllers/DashboardController.php:204 | `$month = now()->month;` | `$month = JapanTime::today()->month;` |
| app/Http/Controllers/DashboardController.php:757 | `$today   = now();` | `$today   = JapanTime::today();` |
| app/Http/Controllers/DashboardController.php:854 | `$prevMonth      = now()->subMonth();` | `$prevMonth      = JapanTime::today()->subMonth();` |
| app/Http/Controllers/RealEstate/ReContractController.php:593 | `$now   = now();` | `$now   = JapanTime::today();` |
| app/Http/Controllers/Housing/HsContractListController.php:586 | `$now = now();` | `$now = JapanTime::today();` |
| app/Http/Controllers/Housing/HousingDashboardController.php:297 | `$now = now();` | `$now = JapanTime::today();` |
| app/Http/Controllers/Dad/ProjectController.php:385 | `$now = now();` | `$now = JapanTime::today();` |
| app/Http/Controllers/Dad/EmployeeController.php:27 | `->orWhere('end_date', '>=', now()->toDateString());` | `->orWhere('end_date', '>=', JapanTime::today()->toDateString());` |
| app/Http/Controllers/Dad/EmployeeController.php:48 | `->orWhere('end_date', '>=', now()->toDateString());` | `->orWhere('end_date', '>=', JapanTime::today()->toDateString());` |
| app/Http/Controllers/Zeal/InquiryController.php:55 | `$months->push(now()->subMonths($i)->format('Y-m'));` | `$months->push(JapanTime::today()->subMonths($i)->format('Y-m'));` |
| app/Http/Controllers/Zeal/SimulationController.php:768・770・771 | `date('Y-m')`（3 か所。外側の `date('Y', strtotime(…))` はそのまま） | `JapanTime::today()->format('Y-m')` |
| app/Http/Controllers/Zeal/SimulationController.php:775 | `(int) now()->year` | `JapanTime::today()->year` |
| app/Http/Controllers/Admin/TenantImportController.php:970 | `if ($endDate > now()->format('Y-m-d')) {` | `if ($endDate > JapanTime::today()->format('Y-m-d')) {` |
| app/Http/Controllers/TransactionController.php:65 | `$now = now();` | `$now = JapanTime::today();` |
| app/Http/Controllers/TransactionController.php:258 | `$now = now();` | `$now = JapanTime::today();` |
| app/Services/ScheduleCardService.php:39 | `$today = ($today ?? CarbonImmutable::today())->startOfDay();` | `$today = ($today ?? JapanTime::today()->toImmutable())->startOfDay();` |
| app/Services/ScheduleBoardService.php:68 | `$today = ($today ?? CarbonImmutable::today())->startOfDay();` | `$today = ($today ?? JapanTime::today()->toImmutable())->startOfDay();` |
| app/Services/Tenant/RentalIncomeService.php:100 | `$thisMonth = now()->startOfMonth();` | `$thisMonth = JapanTime::today()->startOfMonth();` |
| app/Services/Tenant/ContractAnalysisService.php:142 | `$thisYear = (int) now()->year;` | `$thisYear = JapanTime::today()->year;` |
| app/Models/Investment.php:128 | `$now = now();` | `$now = JapanTime::today();` |
| app/Models/ZealPlan.php:69 | `$date ??= now();` | `$date ??= JapanTime::today();` |
| app/Models/ZealMember.php:108 | `return $this->birthday->age;` | `return (int) $this->birthday->diffInYears(JapanTime::today());` |

ビュー 2 本（`@php` の中の 1 行を 2 行にする）:

```php
    // resources/views/mansion/contracts/index.blade.php:34 と resources/views/mansion/parking-contracts/index.blade.php:22
    // 今: $currentFiscalYear = now()->month >= 5 ? now()->year : now()->year - 1;
    $jpToday = \App\Support\JapanTime::today();
    $currentFiscalYear = $jpToday->month >= 5 ? $jpToday->year : $jpToday->year - 1;
```

⚠ `ScheduleCardService` / `ScheduleBoardService` の `use Carbon\CarbonImmutable;` は型の宣言で使っているので残す。
⚠ `ZealMember::age()` の `->age` は Carbon の中で `now()`（UTC の瞬間）と比べている（Carbon 3 の `age` は `(int) $this->diffInYears()`）。

既存テスト `tests/Feature/DashboardControllerTest.php` の 218 行と 238 行（どちらも同じ 1 行）を 2 行にする
（直さないと、アプリは日本の年度・テストは UTC の年度を使い、日本時間の 5/1 0:00〜8:59 にだけ食い違う）:

```php
        $today = \App\Support\JapanTime::today();
        $fy = $today->month >= 5 ? $today->year : $today->year - 1;
```

- [ ] **Step 4: 通ることを確かめる**

Run: 上と同じ `--filter`（7 tests PASS）＋ `--filter 'DashboardControllerTest|ScheduleBoardTest|ScheduleCardAxisTest|ScheduleDateStateTest|RentalIncomeServiceTest|InvestmentRecoveryTest|ContractAnalysisTest'` が緑

- [ ] **Step 5: 全件 → コミット**

```bash
git add -A app resources/views tests/Feature/JapanBusinessDayTest.php tests/Feature/Schedule/ScheduleTodayJapanTimeTest.php \
  tests/Feature/DashboardControllerTest.php
git status --porcelain
git commit -F- <<'EOF'
fix: 年度・当月・工程表などの判定を日本の日付で行う

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 5: 全件分類の走査テスト 2 本

**Files:**
- Create: `tests/Feature/StoredTimestampDisplayScanTest.php` / `tests/Feature/ClockReadScanTest.php`

⚠ どちらも 2026-09-19 に scratchpad の試作で 13.x を走査し、改修前の全件（表示 24・時計 77）をちょうど拾うことを確かめた形。

- [ ] **Step 1: 保存された日時の表示の走査を書く**

`tests/Feature/StoredTimestampDisplayScanTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansModelRelations;
use Tests\TestCase;

/**
 * 保存された日時（TIMESTAMP 列。UTC で保存）を、整形するときは必ず JapanTime::format() を通す（docs/RULES.md Bug #61）。
 *
 * ⚠ 属性の名前はモデルから機械的に集める（usesTimestamps() の作成・更新の列・SoftDeletes の削除の列・datetime キャスト）。
 *   date キャストの `_at`（repairs.started_at など）は自動で外れる＝名前で決め打ちしない（Top trap #13）。
 * ⚠ 見るのは直接の連鎖（`->created_at->format(` / `?->format(` / `optional($x->created_at)->format(` / `->year` など）。
 *   変数に入れてから整形する形は見えない。
 */
class StoredTimestampDisplayScanTest extends TestCase
{
    use ScansModelRelations;

    /** 理由つきで許す直接の整形: 相対パス => [件数, 理由]（今は無い） */
    private const ALLOWED = [];

    private const CALENDAR = 'format|isoFormat|translatedFormat|to\w*String|diffForHumans|toJSON|toISOString';

    private const FIELDS = 'year|month|day|hour|minute|second|dayOfWeek|dayOfYear|weekOfYear|quarter';

    /** @return list<string> */
    private function timestampAttributes(): array
    {
        $names = [];
        foreach ($this->modelClasses() as $class) {
            $model = new $class();
            if ($model->usesTimestamps()) {
                foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
                    if ($column !== null) {
                        $names[$column] = true;
                    }
                }
            }
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $names[$model->getDeletedAtColumn()] = true;
            }
            foreach ($model->getCasts() as $attribute => $cast) {
                $base = strtolower(explode(':', (string) $cast)[0]);
                if (in_array($base, ['datetime', 'immutable_datetime', 'timestamp', 'custom_datetime', 'immutable_custom_datetime'], true)) {
                    $names[$attribute] = true;
                }
            }
        }
        ksort($names);

        return array_keys($names);
    }

    /** @param list<string> $names  @return list<array{int, string}> [行, 一致した文字列] */
    private function directFormats(string $code, array $names): array
    {
        $alt = implode('|', array_map(fn (string $n) => preg_quote($n, '/'), $names));
        $patterns = [
            '/->(?:' . $alt . ')\b\s*\??->\s*(?:(?:' . self::CALENDAR . ')\s*\(|(?:' . self::FIELDS . ')\b)/',
            '/\b(?:optional|Carbon::parse|CarbonImmutable::parse|Carbon::make)\(\s*\$[\w>\-]*->(?:' . $alt . ')\s*\)\s*\??->\s*(?:' . self::CALENDAR . ')\s*\(/',
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$text, $offset]) {
                    $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, $text];
                }
            }
        }

        return $found;
    }

    private function withoutComments(string $path): string
    {
        $source = File::get($path);
        $keepNewlines = fn (array $m) => str_repeat("\n", substr_count($m[0], "\n"));

        if (str_ends_with($path, '.blade.php')) {
            $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $keepNewlines, $source);
            $source = preg_replace_callback('#/\*.*?\*/#s', $keepNewlines, $source);

            return preg_replace('#^([ \t]*)//[^\n]*#m', '$1', $source); // 行頭の // だけ（https:// を残す）
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<string, list<array{int, string}>> 相対パス => 一致 */
    private function scan(): array
    {
        $names = $this->timestampAttributes();
        $hits = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $found = $this->directFormats($this->withoutComments($file->getPathname()), $names);
                if ($found !== []) {
                    $hits[str_replace(base_path() . '/', '', $file->getPathname())] = $found;
                }
            }
        }

        return $hits;
    }

    public function test_timestamp_attributes_are_collected_from_the_models(): void
    {
        $names = $this->timestampAttributes();

        foreach (['created_at', 'updated_at', 'deleted_at', 'last_login_at', 'changed_at', 'logged_in_at', 'email_verified_at'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} を TIMESTAMP の属性として拾えていない");
        }
        // date キャストの _at は拾わない（名前で決め打ちしていない証拠）
        foreach (['started_at', 'completed_at', 'executed_at'] as $dateOnly) {
            $this->assertNotContains($dateOnly, $names, "{$dateOnly} は date キャストなのに TIMESTAMP として拾っている");
        }
    }

    public function test_stored_timestamps_are_never_formatted_directly(): void
    {
        $hits = $this->scan();
        $problems = [];

        foreach ($hits as $path => $found) {
            $allowed = self::ALLOWED[$path][0] ?? 0;
            if (count($found) !== $allowed) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}";
                }
            }
        }
        foreach (self::ALLOWED as $path => [$count]) {
            if (! isset($hits[$path])) {
                $problems[] = "{$path}: 一覧にあるのに直接の整形が 0 件（古い項目）";
            }
        }

        $this->assertSame([], $problems, "保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:\n" . implode("\n", $problems));
    }

    public function test_japan_time_format_is_used_where_timestamps_are_shown(): void
    {
        $count = 0;
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() === 'php') {
                    $count += preg_match_all('/JapanTime::format\(/', $this->withoutComments($file->getPathname()));
                }
            }
        }

        $this->assertGreaterThanOrEqual(24, $count, '走査が空振りしている（JapanTime::format() の呼び出しが少なすぎる）');
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        $names = ['created_at', 'last_login_at'];
        $caught = [
            '$c->created_at->format(\'Y\')',
            '$c->created_at?->format(\'Y\')',
            '$c->created_at ->format(\'Y\')',
            'optional($c->created_at)->format(\'Y\')',
            'Carbon::parse($c->created_at)->format(\'Y\')',
            '$c->created_at->toDateString()',
            '$c->created_at->diffForHumans()',
            '$u->last_login_at->year',
        ];
        $ignored = [
            'JapanTime::format($c->created_at)',
            '$c->contract_date->format(\'Y\')',
            '$c->created_at_label',
            '$c->created_atx->format(\'Y\')',
            '$c->created_at === null',
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->directFormats($sample, $names), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->directFormats($sample, $names), "拾うべきでない: {$sample}");
        }
    }

    public function test_comments_are_dropped_before_scanning(): void
    {
        $path = sys_get_temp_dir() . '/scan-' . uniqid('', true) . '.php';
        File::put($path, "<?php\n// \$c->created_at->format('Y')\n/** \$c->created_at->format('Y') */\n\$ok = 1;\n");
        $blade = sys_get_temp_dir() . '/scan-' . uniqid('', true) . '.blade.php';
        File::put($blade, "{{-- \$c->created_at->format('Y') --}}\n  // \$c->created_at->format('Y')\n<p>ok</p>\n");

        try {
            $this->assertSame([], $this->directFormats($this->withoutComments($path), ['created_at']));
            $this->assertSame([], $this->directFormats($this->withoutComments($blade), ['created_at']));
        } finally {
            File::delete([$path, $blade]);
        }
    }
}
```

- [ ] **Step 2: 時計の読み取りの走査を書く**

`tests/Feature/ClockReadScanTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 時計の読み取り（「今」を使う呼び出し）を全件分類する（docs/RULES.md Bug #61・Top trap #13）。
 *
 * アプリの timezone は UTC なので、now() / today() / date('Y') などで作る「今日」「今月」「今年度」は
 * 日本時間の 0:00〜8:59 に前日になる。暦の日付が要る所は App\Support\JapanTime::today() を使う。
 *
 * ⚠ ビューは 0 件（ビューで時計を読む用途は表示と既定値＝どれも日本の暦の日付）。
 * ⚠ PHP は ALLOWED（ファイルごとの件数と理由）に載っているものだけ許す。件数が合わない・載っていない・
 *   古い項目は落とす。瞬間を保存する（TIMESTAMP 列）・期限・運用のように UTC の瞬間でよい所だけ、理由を書いて載せる。
 * ⚠ 見えないもの: 変数に入れた Carbon をあとで暦として使う形（`$t = now(); … $t->year`）は、呼び出しとしては
 *   拾うがその使い道は見ない（載せるときに理由で説明する）。
 * ⚠ 「今」を使わない呼び出しは数えない: 引数 2 つの date()（保存された日付の整形）・文字列の変数を渡す strtotime()・
 *   日まで書式にある createFromFormat()。
 */
class ClockReadScanTest extends TestCase
{
    /** @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由] */
    private const ALLOWED = [
        'app/Support/JapanTime.php'                           => [1, '日本の今日を作る部品そのもの'],
        'app/Http/Controllers/Auth/AuthController.php'        => [2, 'last_login_at・logged_in_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Http/Controllers/Tenant/PropertyController.php'  => [1, 'property_change_logs.changed_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Http/Controllers/Zeal/SimulationController.php'  => [1, '一括 insert の created_at・updated_at（TIMESTAMP 列。Eloquent を通らないので手で入れる）'],
        'app/Http/Controllers/Zeal/SheetImportController.php' => [1, 'zeal_sheet_imports.created_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Console/Commands/BackupCommand.php'              => [2, '運用: 日本時間を明示している（CarbonImmutable::now(\'Asia/Tokyo\')）'],
        'app/Console/Commands/MailTestCommand.php'            => [1, '運用: 日本時間を明示している'],
        'routes/console.php'                                  => [1, '運用: 日本時間を明示している'],
    ];

    /** @return list<array{int, string}> [行, 呼び出し] */
    private function clockReads(string $code): array
    {
        $found = [];
        $add = function (int $offset, string $text) use (&$found, $code) {
            $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, $text];
        };
        $notMember = '(?<![\w$>:.\\\\])';

        // 1. now( / today(（ヘルパー関数）
        if (preg_match_all('/' . $notMember . '(?:now|today)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 2. Carbon::now() / CarbonImmutable::today() / Date::now() など
        if (preg_match_all('/\b(?:Carbon|CarbonImmutable|Date)::(?:now|today|yesterday|tomorrow)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 3. PHP の関数: date/gmdate/idate は引数 1 つ・time は引数なし・mktime は 6 未満・strtotime は文字列そのもの
        if (preg_match_all('/' . $notMember . '(date|gmdate|idate|time|mktime|strtotime)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $k => [$name]) {
                $open = $m[0][$k][1] + strlen($m[0][$k][0]) - 1;
                $args = $this->argumentsAt($code, $open);
                if ($args === null) {
                    continue;
                }
                $count = $this->topLevelArgumentCount($args);
                $isClock = match ($name) {
                    'date', 'gmdate', 'idate' => $count === 1,
                    'time'                    => $count === 0,
                    'mktime'                  => $count < 6,
                    'strtotime'               => $count === 1 && preg_match('/^\s*([\'"])[^\'"]*\1\s*$/', $args) === 1,
                };
                if ($isClock) {
                    $add($m[0][$k][1], "{$name}({$args})");
                }
            }
        }
        // 4. new DateTime() / new Carbon('now')
        if (preg_match_all('/\bnew\s+\\\\?(?:\w+\\\\)*(?:DateTime|DateTimeImmutable|Carbon|CarbonImmutable)\s*\(\s*(?:([\'"])(?:now|today)\1)?\s*\)/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 5. 暗黙に「今」と比べる: 日付の属性の ->age・引数なしの diffIn…() / diffForHumans()・isToday() など
        $implicit = '/(?:birthday|_date|_on|_at)->age\b'
            . '|->(?:isToday|isPast|isFuture|isYesterday|isTomorrow|isCurrentDay|isCurrentMonth|isCurrentYear|isNextMonth|isLastMonth|isNextYear|isLastYear)\s*\('
            . '|->diff(?:In\w+|ForHumans)\s*\(\s*\)/';
        if (preg_match_all($implicit, $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 6. createFromFormat の書式に年・月があって日が無い（無い「日」は今日から補われる）
        if (preg_match_all('/createFromFormat\(\s*([\'"])([^\'"]*)\1/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[2] as $k => [$format]) {
                if (preg_match('/[YymnMF]/', $format) && ! preg_match('/[djDlNSwz!|]/', $format)) {
                    $add($m[0][$k][1], "createFromFormat('{$format}')");
                }
            }
        }

        return $found;
    }

    /** "(" の位置から対応する ")" までの中身。閉じていなければ null */
    private function argumentsAt(string $code, int $open): ?string
    {
        $depth = 0;
        for ($i = $open, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')' && --$depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }

        return null;
    }

    private function topLevelArgumentCount(string $args): int
    {
        if (trim($args) === '') {
            return 0;
        }
        $depth = 0;
        $count = 1;
        $quote = null;
        for ($i = 0, $len = strlen($args); $i < $len; $i++) {
            $c = $args[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function withoutComments(string $path): string
    {
        $source = File::get($path);
        $keepNewlines = fn (array $m) => str_repeat("\n", substr_count($m[0], "\n"));

        if (str_ends_with($path, '.blade.php')) {
            $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $keepNewlines, $source);
            $source = preg_replace_callback('#/\*.*?\*/#s', $keepNewlines, $source);

            return preg_replace('#^([ \t]*)//[^\n]*#m', '$1', $source); // 行頭の // だけ（https:// を残す）
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array{0: array<string, list<array{int, string}>>, 1: int, 2: int} [相対パス => 一致, PHP の本数, Blade の本数] */
    private function scan(): array
    {
        $hits = [];
        $php = 0;
        $blade = 0;
        foreach ([app_path(), base_path('routes'), config_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                str_ends_with($file->getFilename(), '.blade.php') ? $blade++ : $php++;
                $found = $this->clockReads($this->withoutComments($file->getPathname()));
                if ($found !== []) {
                    $hits[str_replace(base_path() . '/', '', $file->getPathname())] = $found;
                }
            }
        }

        return [$hits, $php, $blade];
    }

    public function test_the_scan_sees_enough_files(): void
    {
        [, $php, $blade] = $this->scan();

        $this->assertGreaterThanOrEqual(250, $php, '走査が空振りしている（PHP のファイルが少なすぎる）');
        $this->assertGreaterThanOrEqual(230, $blade, '走査が空振りしている（Blade のファイルが少なすぎる）');
    }

    public function test_views_never_read_the_clock(): void
    {
        [$hits] = $this->scan();
        $problems = [];
        foreach ($hits as $path => $found) {
            if (str_starts_with($path, 'resources/views/')) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}";
                }
            }
        }

        $this->assertSame([], $problems, "ビューで時計を読んでいる（日本時間の 0:00〜8:59 に前日になる。\\App\\Support\\JapanTime::today() を使う）:\n" . implode("\n", $problems));
    }

    public function test_php_clock_reads_are_classified(): void
    {
        [$hits] = $this->scan();
        $problems = [];

        foreach ($hits as $path => $found) {
            if (str_starts_with($path, 'resources/views/')) {
                continue;
            }
            if (! isset(self::ALLOWED[$path])) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}  （分類されていない。日本の今日なら JapanTime::today()、瞬間なら理由を書いて ALLOWED へ）";
                }
                continue;
            }
            if (count($found) !== self::ALLOWED[$path][0]) {
                $problems[] = "{$path}: 件数が " . count($found) . '（一覧は ' . self::ALLOWED[$path][0] . '）: '
                    . implode(' / ', array_map(fn ($f) => ":{$f[0]} {$f[1]}", $found));
            }
        }
        foreach (array_keys(self::ALLOWED) as $path) {
            if (! isset($hits[$path])) {
                $problems[] = "{$path}: 一覧にあるのに時計を読んでいない（古い項目）";
            }
        }

        $this->assertSame([], $problems, "時計の読み取りが分類と合わない:\n" . implode("\n", $problems));
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        $caught = [
            'now()', 'now(\'Asia/Tokyo\')', 'today()', 'Carbon::now()', '\Carbon\CarbonImmutable::today()',
            'Date::now()', 'date(\'Y-m-d\')', 'time()', 'strtotime(\'today\')', 'new DateTime()',
            'new \DateTimeImmutable(\'now\')', '$m->birthday->age', '$d->isPast()', '$d->diffInDays()',
            'createFromFormat(\'Y-m\', $m)',
        ];
        $ignored = [
            'JapanTime::today()', '$x->now()', '$this->today()', 'date(\'Y\', $ts)', 'date(\'Y\', strtotime($stored))',
            'strtotime($stored)', '$inquiry->age', '$d->diffInDays($other)', 'createFromFormat(\'Y-m-d\', $s)',
            'createFromFormat(\'H:i:s\', $t)', 'Date.now()', 'new Date()', '$q->update($a)', '$todayLabel',
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->clockReads($sample), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->clockReads($sample), "拾うべきでない: {$sample}");
        }
    }

    public function test_comments_are_dropped_before_scanning(): void
    {
        $php = sys_get_temp_dir() . '/clock-' . uniqid('', true) . '.php';
        File::put($php, "<?php\n// now()\n/** today() */\n\$ok = 1;\n");
        $blade = sys_get_temp_dir() . '/clock-' . uniqid('', true) . '.blade.php';
        File::put($blade, "{{-- now() --}}\n  // date('Y')\n<a href=\"https://example.com\">ok</a>\n");

        try {
            $this->assertSame([], $this->clockReads($this->withoutComments($php)));
            $this->assertSame([], $this->clockReads($this->withoutComments($blade)));
        } finally {
            File::delete([$php, $blade]);
        }
    }
}
```

- [ ] **Step 3: 通ることを確かめる**

Run: `APP_KEY=… ./vendor/bin/phpunit --filter 'StoredTimestampDisplayScanTest|ClockReadScanTest'`
Expected: PASS（10 tests）。落ちたら、表示されたファイルと行を Task 2〜4 の表と突き合わせて**直す**（ALLOWED を広げて通さない）。
ALLOWED の件数が合わないときは、その行が本当に瞬間の保存・運用かを読んで確かめる。

- [ ] **Step 4: 全件 → コミット**

```bash
git add tests/Feature/StoredTimestampDisplayScanTest.php tests/Feature/ClockReadScanTest.php
git commit -F- <<'EOF'
test: 日時の直接の整形と時計の読み取りを全件分類で守る

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 6: 変異テスト（Bug #44 の作法。親セッションが行う）

⚠ **サブエージェントに任せない**（並行のレビューがあると worktree を書き換える測定が汚れる）。

- [ ] **Step 1: 作法**

各変異ごとに: ① `git status --porcelain` が空 → ② 変異を当てる → ③ `git diff --stat` が**非空**（新しいファイルは `git status` で確かめる）→
④ **全件**を `--log-junit` で流す → ⑤ 落ちたテストの集合と**理由の 1 行目**を記録 → ⑥ `git restore --source=HEAD --staged --worktree -- <file>`
（新しいファイルは削除）→ ⑦ `git status --porcelain` が空。落ちた理由が意図と違えば、その測定は無効として当て直す。

- [ ] **Step 2: 変異の表**

| # | 変異 | 期待して落ちるテスト |
|---|---|---|
| M00 | カナリア: `housing/properties/show.blade.php` に `{{ $canaryUndefinedVariable }}` | HousingTimestampDisplayTest（500）ほか建売の詳細を開くテスト |
| M01 | `JapanTime::format()` から `->setTimezone(self::ZONE)` を外す | JapanTimeTest（format 3 本）・HousingTimestampDisplayTest×2・UserLastLoginDisplayTest・AttachmentTimestampDisplayTest×2 |
| M02 | `JapanTime::today()` の `Carbon::now(self::ZONE)` を `Carbon::now()` に | JapanTimeTest（today 系）・InquiryJapanDateTest×2・DashboardJapanDateTest・JapanBusinessDayTest・ScheduleTodayJapanTimeTest |
| M03 | `JapanTime::today()` を `return Carbon::now(self::ZONE)->startOfDay();`（日本時間の 0:00）に | JapanTimeTest::test_today_is_midnight_in_the_app_timezone・::test_today_equals_a_date_cast_attribute_of_the_same_day・JapanBusinessDayTest::test_zeal_months_turn_over_at_japan_midnight（isFutureMonth） |
| M04 | `housing/properties/show.blade.php:311` を `$property->created_at->format('Y/m/d H:i')` に戻す | HousingTimestampDisplayTest::test_registered…・StoredTimestampDisplayScanTest::test_stored_timestamps… |
| M05 | `realestate/contracts/show.blade.php:294` を戻す（振る舞いのテストが無い箇所） | StoredTimestampDisplayScanTest だけ |
| M06 | `tenant/inquiries/create.blade.php:123` を `now()->format('Y-m-d')` に戻す | InquiryJapanDateTest::test_the_create_form…・ClockReadScanTest::test_views_never_read_the_clock |
| M07 | `ReContractController::getCurrentFiscalYear()` を `now()` に戻す | JapanBusinessDayTest::test_the_may_fiscal_year…・ClockReadScanTest::test_php_clock_reads_are_classified |
| M08 | `ZealPlan::isCampaignActive()` を `now()` に戻す | JapanBusinessDayTest::test_a_campaign…・ClockReadScanTest |
| M09 | `ZealMember::age()` を `$this->birthday->age` に戻す | JapanBusinessDayTest::test_a_zeal_members_age…・ClockReadScanTest |
| M10 | 新しいファイル `app/Support/ClockCanary.php`（`<?php namespace App\Support; function clockCanary() { return now(); }`）を足す | ClockReadScanTest::test_php_clock_reads_are_classified（分類されていない） |
| M11 | ALLOWED の AuthController の件数を 3 に | ClockReadScanTest（件数） |
| M12 | ALLOWED に時計を読まないファイル（`app/Support/VacancyRate.php`）を足す | ClockReadScanTest（古い項目） |
| M13 | ClockReadScanTest の withoutComments から T_COMMENT の読み飛ばしを外す | ClockReadScanTest::test_comments_are_dropped… |
| M14 | StoredTimestampDisplayScanTest の 1 本目の正規表現から `\??` を外す | ::test_the_detector…（`?->format` が拾えない） |
| M15 | StoredTimestampDisplayScanTest の SoftDeletes の分岐を外す | ::test_timestamp_attributes…（deleted_at） |
| M16 | `zeal/inquiries/index.blade.php:93` を `createFromFormat('Y-m', $m)` に戻す | ClockReadScanTest::test_views_never_read_the_clock |
| M17 | `components/attachment-section.blade.php:27` を戻す | AttachmentTimestampDisplayTest::test_the_section…・StoredTimestampDisplayScanTest |
| M18 | `AttachmentController.php:169` を戻す | AttachmentTimestampDisplayTest::test_the_delete_response…・StoredTimestampDisplayScanTest |
| M19 | `ScheduleCardService.php:39` を `CarbonImmutable::today()` に戻す | ScheduleTodayJapanTimeTest・ClockReadScanTest |
| M20 | `InquiryController.php:412` を `now()->toDateString()` に戻す | InquiryJapanDateTest::test_the_automatic_history…・ClockReadScanTest |
| M21 | `InquiryController.php:442` を `date('Y')` に戻す | InquiryJapanDateTest::test_the_create_form…・ClockReadScanTest |
| M22 | `mansion/dashboard.blade.php:211` を `now()` に戻す | DashboardJapanDateTest・ClockReadScanTest |
| M23 | 等価（緑のはず）: `app/Support/VacancyRate.php` のどこかの関数の中に `$unused = date('Y', 0);` を足す | 緑（引数 2 つの date() は時計を読まない＝走査が過剰に拾わない証拠） |

- [ ] **Step 3: 漏れを塞ぐ**

期待と違った変異（緑のまま・別の理由で赤）があれば、テストを足してその変異が赤になること・足す前は緑だったこと（反例）を測る。
漏れが 0 件なら、同じ行の隣の不変条件にも当てて測り方を疑う（Task 15 の教訓）。

---

## Task 7: 文書

**Files:**
- Modify: `docs/RULES.md`（Bug カタログの末尾に #61）/ `CLAUDE.md`（Top traps に #19・Display の規約に 1 行）/ `docs/BACKLOG.md`（新しい節）/
  この計画（末尾に実測記録）

- [ ] **Step 1: `docs/RULES.md` の表の末尾（#60 の行の次）に #61 を足す**

表の 1 行（`| 61 | 症状 | 原因 | 直し方 |`）として次を書く:
- 症状: 保存された日時（登録・更新日時・最終ログイン・変更履歴・添付・ファイルの登録日）が一日中 9 時間ずれて出る ／ 「今日」「今月」「今年度」
  （画面の「〇年〇月〇日 時点」・フォームの既定の日付・自動の対応履歴の日付・番号の年・5/1 と 6/1 の年度の切り替え・工程表の今日・年齢・
  キャンペーン期間）が日本時間の 0:00〜8:59 に前日になる。2026-09-19 の決裁 段階1 の実ブラウザ確認（Task 16 の F5・F6）で発覚
- 原因: `config/app.php` の `timezone` が `'UTC'` の直書き（最初のコミットから）。保存は UTC の壁時計で正しいが、表示が変換せず、
  `now()` / `today()` / `date()` の暦の値が UTC の日付。`Carbon` の `->age` と `createFromFormat('Y-m')`（無い日を今日から補う）も同じ
- 直し方: `App\Support\JapanTime`（`format()` と `today()`）。アプリの timezone・保存・ログは UTC のまま（利用者の判断＝案 (a)）。
  ⚠ `today()` は日本の日付の **UTC の 0:00**（日本時間の 0:00 で返すと date 属性・`Carbon::create()` と 9 時間ずれ、
  `ZealFiscalYear::isFutureMonth()` が当月を来月と判定する。変異 M03 で実測）。⚠ TIMESTAMP 列への保存・期限は `now()` のまま。
  ⚠ キャンペーン期間は終了日の当日も適用中になった（以前は終了日の 9:00 以降が対象外）。
  走査テスト `StoredTimestampDisplayScanTest`（属性名はモデルから機械的に集める。date キャストの `_at` は外れる）と
  `ClockReadScanTest`（ビューは 0 件・PHP はファイルごとの件数と理由）が守る。変異の結果は実装計画の実測記録

- [ ] **Step 2: `CLAUDE.md`**

Top traps の表の #18 の次に:

```markdown
| 19 | 保存された日時を `->format()` で直接出す ／ 「今日」「今月」「今年度」を `now()` `today()` `date('Y')` で作る（アプリの timezone は UTC。表示が一日中 9 時間ずれ、「今日」は日本時間の 0:00〜8:59 に前日になる）| 保存された日時は `\App\Support\JapanTime::format($x->created_at)`、日本の今日は `JapanTime::today()`（日本の日付の **UTC の 0:00**。date 属性とそのまま比べられる）。TIMESTAMP 列への保存・期限は `now()` のまま。走査テスト `StoredTimestampDisplayScanTest`・`ClockReadScanTest` が止める。Bug #61 |
```

Conventions の Display の箇条書きに 1 行:

```markdown
- 日時: 保存された日時（TIMESTAMP）は `JapanTime::format()` で日本時間にして出す。「今日」は `JapanTime::today()`（アプリの timezone は UTC のまま）
```

- [ ] **Step 3: `docs/BACKLOG.md` に節を足す**（「バックログ完了状況」の直前。見出しは `## ✅ 日時の表示と「今日」を日本時間にそろえる — 本番未反映`）

中身: 依頼の経緯（Task 16 の F5・F6 から）・案 (a) を選んだこと・変えた範囲（表示 24・「今日」の数）・部品と走査テスト・振る舞いが変わった所
（キャンペーン期間）・範囲外（月の足し引きの月末の溢れ・DAD の年度の始まり・決裁 段階1 のブランチの 2 か所）・検証の結果・本番反映の手順
（DB 変更なし・`./deploy.sh`・反映後に基幹の利用者一覧の自分の最終ログインが今の日本時間で出ることを見る）。

- [ ] **Step 4: この計画の末尾に「実測記録」を足す**（Task 6 の表の結果・落ちた理由の文言・漏れと塞いだテスト）

- [ ] **Step 5: コミット**

```bash
git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-19-japan-time.md
git commit -F- <<'EOF'
docs: 日時を日本時間にそろえた記録を残す

Co-Authored-By: <自分のシステム文脈にある行>
EOF
```

---

## Task 8: 確かめる（親セッション）

- [ ] **Step 1: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
K="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
APP_KEY="$K" php artisan view:cache
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done
APP_KEY="$K" php artisan view:clear
```

Expected: INVALID 0 件

- [ ] **Step 2: ローカルの実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`。`preview_start` は使わない）

登録日時が +9 時間で出る（建売の詳細・不動産の契約の詳細）・添付の一覧・問合せ登録の既定の日付（日本時間の 0:00〜8:59 に見られれば
前日にならないことまで）・コンソールのエラー 0 件。

- [ ] **Step 3: 本番反映は利用者の明示の承認を得てから**（DB 変更なし・`./deploy.sh` のみ）。
反映後、基幹の利用者一覧の自分の最終ログインが今の日本時間で出ることを見る。

---

## 実測記録（Task 6 の変異テスト。2026-09-23。docs/RULES.md Bug #44 の作法）

作法: ① `git status --porcelain` が空 → ② 変異を当てる（**出現がちょうど 1 回でなければ中止**）→
③ 着弾を確認（非空）→ ④ 全件を `--log-junit` で流す → ⑤ 落ちたテストと**理由の 1 行目**を記録 →
⑥ `git restore` で復元 → ⑦ 空を再確認。**32 通りすべて ①〜⑦ を通し、復元後の清浄も全件で確認した。**

基準: `OK (1752 tests, 10775 assertions)`

| # | 変異 | 結果 | 落ちたテスト（理由の 1 行目） |
|---|---|---|---|
| M00 | カナリア: 建売詳細に未定義変数を置く | 赤 19 | `Housing\HousingConstructionStartDateTest::test_the_property_show_page_pairs_the_label_with_its_own_value`<br>Expected response status code [200] but received 500.<br>`Housing\HousingTimestampDisplayTest::test_registered_and_updated_times_are_shown_in_japan_time`<br>Expected response status code [200] but received 500.<br>`Housing\HousingTimestampDisplayTest::test_file_upload_dates_are_japanese_dates`<br>Expected response status code [200] but received 500.<br>…ほか 16 本 |
| M01 | format() から ->setTimezone(self::ZONE) を外す | 赤 11 | `Admin\UserLastLoginDisplayTest::test_last_login_is_shown_in_japan_time`<br>最終ログインが日本時間になっていない<br>`AttachmentTimestampDisplayTest::test_the_section_shows_upload_and_deletion_times_in_japan_time`<br>登録の日時が日本時間になっていない<br>`AttachmentTimestampDisplayTest::test_the_delete_response_carries_the_japan_time`<br>Failed asserting that two strings are identical.<br>…ほか 8 本 |
| J-a | format() の setTimezone を addHours(9) に（Task 1 レビューが見つけた死角） | 赤 1 | `Support\JapanTimeTest::test_format_shifts_the_zone_rather_than_adding_nine_hours`<br>Failed asserting that two strings are identical. |
| J-b | CarbonImmutable::instance を Carbon::instance に | **緑** | （緑） |
| J-c | format() の引数の型を ?DateTimeInterface から ?Carbon に狭める | 赤 2 | `Support\JapanTimeTest::test_format_accepts_immutable_values_and_leaves_the_argument_alone`<br>TypeError: App\Support\JapanTime::format(): Argument #1 ($at) must be of type ?I<br>`Support\JapanTimeTest::test_format_accepts_plain_php_date_objects`<br>TypeError: App\Support\JapanTime::format(): Argument #1 ($at) must be of type ?I |
| M02 | today() の Carbon::now(self::ZONE) を Carbon::now() に | 赤 15 | `JapanBusinessDayTest::test_the_may_fiscal_year_turns_over_at_japan_midnight`<br>App\Http\Controllers\DashboardController::getCurrentFiscalYear: 5/1 の朝は新しい年度<br>`JapanBusinessDayTest::test_the_dashboard_half_turns_over_at_japan_midnight`<br>Failed asserting that two strings are identical.<br>`JapanBusinessDayTest::test_zeal_months_turn_over_at_japan_midnight`<br>6/1 の朝は新しい年度<br>…ほか 12 本 |
| M03 | today() を日本時間の 0:00 にする | 赤 13 | `Tests\Unit\Tenant\InvestmentRecoveryTest::test_counts_from_completion_date`<br>Failed asserting that 200000 matches expected 300000.<br>`Tests\Unit\Tenant\InvestmentRecoveryTest::test_existing_tenant_straddling_completion_uses_full_rent`<br>Failed asserting that 200000 matches expected 300000.<br>`Tests\Unit\Tenant\InvestmentRecoveryTest::test_prorated_first_month_counts_daily_rent`<br>Failed asserting that 170000 matches expected 270000.<br>…ほか 10 本 |
| M04 | 建売詳細の登録日時を直接 format に戻す | 赤 3 | `Housing\HousingTimestampDisplayTest::test_registered_and_updated_times_are_shown_in_japan_time`<br>property: 登録の日時が日本時間になっていない<br>`StoredTimestampDisplayScanTest::test_stored_timestamps_are_never_formatted_directly`<br>保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:<br>`StoredTimestampDisplayScanTest::test_japan_time_format_is_used_where_timestamps_are_shown`<br>JapanTime::format() の呼び出しが 24 件を下回った。走査の空振りか、検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・- |
| M26 | 建売詳細を『死角の形』（変数に入れてから整形）にする | 赤 2 | `Housing\HousingTimestampDisplayTest::test_registered_and_updated_times_are_shown_in_japan_time`<br>property: 登録の日時が日本時間になっていない<br>`StoredTimestampDisplayScanTest::test_japan_time_format_is_used_where_timestamps_are_shown`<br>JapanTime::format() の呼び出しが 24 件を下回った。走査の空振りか、検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・- |
| M05 | 不動産契約詳細の登録日時を戻す（振る舞いのテストが無い箇所） | 赤 2 | `StoredTimestampDisplayScanTest::test_stored_timestamps_are_never_formatted_directly`<br>保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:<br>`StoredTimestampDisplayScanTest::test_japan_time_format_is_used_where_timestamps_are_shown`<br>JapanTime::format() の呼び出しが 24 件を下回った。走査の空振りか、検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・- |
| M06 | 問合せ登録の既定の日付を now() に戻す | 赤 2 | `ClockReadScanTest::test_views_never_read_the_clock`<br>ビューで時計を読んでいる（日本時間の 0:00〜8:59 に前日になる。\App\Support\JapanTime::today() を使う）。<br>`Tenant\InquiryJapanDateTest::test_the_create_form_uses_the_japanese_date_on_new_years_morning`<br>問合せ日の既定が日本の今日でない |
| M07 | 不動産契約の年度を now() に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`JapanBusinessDayTest::test_the_may_fiscal_year_turns_over_at_japan_midnight`<br>App\Http\Controllers\RealEstate\ReContractController::getCurrentFiscalYear: 5/1  |
| M08 | キャンペーン判定を now() に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`JapanBusinessDayTest::test_a_campaign_runs_from_japan_midnight_of_the_start_day_through_the_end_day`<br>開始日の 0:00（日本時間 9/1 0:00） |
| M09 | 年齢を $this->birthday->age に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`JapanBusinessDayTest::test_a_zeal_members_age_goes_up_at_japan_midnight_on_the_birthday`<br>誕生日の朝なのに年齢が上がっていない |
| M10 | 時計を読む新しいファイルを足す | 赤 1 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない: |
| M11 | ALLOWED の AuthController の件数を 3 に | 赤 1 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない: |
| M12 | ALLOWED に時計を読まないファイルを足す | 赤 1 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない: |
| M13 | ClockReadScanTest の withoutComments から T_COMMENT を外す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`ClockReadScanTest::test_comments_are_dropped_before_scanning`<br>Failed asserting that two arrays are identical. |
| M14 | 表示の走査の正規表現から \?? を外す | 赤 1 | `StoredTimestampDisplayScanTest::test_the_detector_catches_what_it_should_and_ignores_the_rest`<br>拾えていない: $c->created_at?->format('Y') |
| M15 | 表示の走査から SoftDeletes の分岐を外す | **緑** | （緑） |
| M27 | コメント除去の /* */ を直す前の形に戻し、sidebar に CSS コメントを足す（2 ファイル + ビュー） | 赤 2 | `ClockReadScanTest::test_comments_are_dropped_before_scanning`<br>Failed asserting that two arrays are not identical.<br>`StoredTimestampDisplayScanTest::test_comments_are_dropped_before_scanning`<br>Failed asserting that two arrays are not identical. |
| M16 | ZEAL 体験予約の月ラベルを createFromFormat('Y-m') に戻す | 赤 1 | `ClockReadScanTest::test_views_never_read_the_clock`<br>ビューで時計を読んでいる（日本時間の 0:00〜8:59 に前日になる。\App\Support\JapanTime::today() を使う）。 |
| M17 | 添付セクションの登録日時を戻す | 赤 3 | `AttachmentTimestampDisplayTest::test_the_section_shows_upload_and_deletion_times_in_japan_time`<br>登録の日時が日本時間になっていない<br>`StoredTimestampDisplayScanTest::test_stored_timestamps_are_never_formatted_directly`<br>保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:<br>`StoredTimestampDisplayScanTest::test_japan_time_format_is_used_where_timestamps_are_shown`<br>JapanTime::format() の呼び出しが 24 件を下回った。走査の空振りか、検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・- |
| M18 | 添付削除の応答の日時を戻す | 赤 3 | `AttachmentTimestampDisplayTest::test_the_delete_response_carries_the_japan_time`<br>Failed asserting that two strings are identical.<br>`StoredTimestampDisplayScanTest::test_stored_timestamps_are_never_formatted_directly`<br>保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:<br>`StoredTimestampDisplayScanTest::test_japan_time_format_is_used_where_timestamps_are_shown`<br>JapanTime::format() の呼び出しが 24 件を下回った。走査の空振りか、検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・- |
| M19 | 工程表カードの今日を CarbonImmutable::today() に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`Schedule\ScheduleTodayJapanTimeTest::test_the_schedule_card_uses_the_japanese_today`<br>工程表の今日が日本の日付でない |
| M20 | 問合せの自動履歴の日付を now() に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`Tenant\InquiryJapanDateTest::test_the_automatic_history_of_a_status_change_is_dated_in_japan`<br>自動の対応履歴の日付が日本の日付でない |
| M21 | 問合せ番号の年を date('Y') に戻す | 赤 2 | `ClockReadScanTest::test_php_clock_reads_are_classified`<br>時計の読み取りが分類と合わない:<br>`Tenant\InquiryJapanDateTest::test_the_create_form_uses_the_japanese_date_on_new_years_morning`<br>問合せ番号の年が日本の年でない |
| M22 | 賃貸マンションのダッシュボードの時点を now() に戻す | 赤 2 | `ClockReadScanTest::test_views_never_read_the_clock`<br>ビューで時計を読んでいる（日本時間の 0:00〜8:59 に前日になる。\App\Support\JapanTime::today() を使う）。<br>`Mansion\DashboardJapanDateTest::test_the_snapshot_date_is_the_japanese_date`<br>Failed asserting that '<!DOCTYPE html>\n |
| M24 | ファイルの登録日から第 2 引数 'Y/m/d' を落とす（建売） | 赤 1 | `Housing\HousingTimestampDisplayTest::test_file_upload_dates_are_japanese_dates`<br>ファイルの登録日が日本の日付になっていない |
| M25 | 図面の登録日から第 2 引数 'Y/m/d' を落とす（分譲地） | **緑** | （緑） |
| M23 | 等価変異: 引数 2 つの date() を足す | **緑** | （緑） |
| M28 | 網の外に 5 月始まりの年度の複製を 1 つ増やす | 赤 1 | `JapanBusinessDayTest::test_every_may_fiscal_year_expression_is_classified`<br>5 月始まりの年度の式の在処が変わった。 |

### 期待と違ったもの: **M15 の 1 件だけ**（調べた結果 真の等価変異）

`timestampAttributes()` の SoftDeletes の分岐を消しても全件が緑だった。調べると Laravel の
`SoftDeletes::initializeSoftDeletes()` が `deleted_at` を `datetime` キャストへ足すので、
下の casts のループと**重なっている**（Buyer / Unit / Attachment / Property の 4 モデルで実測）。
→ **穴ではない**。次の測定で誤読されないよう、その 3 行に理由を書き添えた（`17348e1c`）。

### 意図して緑にしたもの（死角・等価変異の実測）
- **M25**: 第 2 引数 `'Y/m/d'` を落としても緑。走査はソースの**形**しか見ないので**書式の誤りは拾えない**。
  同じ形の M24（建売）は振る舞いのテストがあるので赤 ＝ 守り手の有無がそのまま出た
- **M23**: 引数 2 つの `date()` を足しても緑 ＝ 走査が**過剰に拾わない**ことの証明
- **J-b**: `CarbonImmutable::instance` → `Carbon::instance` は緑。`Carbon::instance()` も clone するので**真の等価変異**

### この測定で裏が取れた重要な事実
- **M26**: 表示を「死角の形」（変数に入れてから整形）に書き換えると、**件数の下限 `MIN_FORMAT_CALLS = 24` だけ**が赤になる
  （走査本体は緑）＝ 下限が死角を捕まえる**唯一の守り手**であることの実測。失敗メッセージもその趣旨を伝えている
- **M27**: コメント除去の `/* */` を直す前の形に戻すと、新しく入れた固定資産が**2 ファイルとも**赤 ＝ 修正が load-bearing
- **M05**: 振る舞いのテストが無い箇所は**走査だけ**が赤になる（設計どおり）
- **J-a**: `setTimezone` → `addHours(9)` は、Task 1 のレビューで足したテスト **1 本だけ**が捕まえる
  （足す前は出力が完全に一致するので原理的に検出不能だった）
- **M00**（カナリア）: 19 本が赤 ＝ 測定装置が worktree のコードを読んでいることの確認

### 実測で分かった計画の誤り（⚠ 本文は直していない）

計画どおりだったかを後から確かめられるよう、**Task 1〜8 の本文はそのまま残す**。実測と食い違った 4 件をここに書き留める。

| 場所 | 計画の記述 | 実測 |
|---|---|---|
| Task 3 の `Files:`（558 行） | `- Modify（ビュー 18）: 下の表` | **19 ファイル / 20 行** |
| Task 4 の Step 4（1028 行） | `上と同じ --filter（7 tests PASS）` | **6**（`JapanBusinessDayTest` 5 ＋ `ScheduleTodayJapanTimeTest` 1）|
| Task 5 の ALLOWED（1302 行） | `'app/Support/JapanTime.php' => [1, …]` | **`[2, …]`**（検出器が `public static function today(): Carbon` の宣言にも自己一致する。**検出器を弱めず件数で吸収**した）|
| `ZealPlan` の docblock（899 行）| `⚠ 振る舞いが変わる唯一の箇所` | **2 か所**（キャンペーン期間 ＋ `ZealMember::age()`。どちらも BACKLOG と docs/RULES.md Bug #61 に記録）|
