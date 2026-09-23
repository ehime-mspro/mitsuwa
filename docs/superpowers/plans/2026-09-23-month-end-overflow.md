# 月末の溢れを直す — 実装計画

- 作業場所: worktree `.claude/worktrees/month-end-overflow`（ブランチ `month-end-overflow`、起点 `13.x` = `e6787c20`）
- 進め方: superpowers:subagent-driven-development（実装 → 仕様適合レビュー → コード品質レビュー）。
  **変異テストと最終確認は親セッションが行う**（サブエージェントに任せない）
- 基準: `OK (1752 tests, 10775 assertions)`（2026-09-23 に worktree で実測）

## 背景

Carbon の `subMonth()` / `addMonth()` / `subMonths($i)` は、**移った先の月にその日が無いと翌月へ溢れる**
（例: 3/31 の 1 か月前 = 2/31 → 3/3）。2026-09-22〜23 の改修（docs/RULES.md Bug #61）で「今日」の基準を
UTC の暦日 → 日本の暦日に変えた結果、既存の溢れが**日本時間の丸一日**出るようになった
（それまでは溢れる日の日本時間 9:00〜23:59 の 15 時間。翌日 0:00〜8:59 は時差と溢れが打ち消し合っていた）。
我々が作ったバグではないが、露出を広げたのは事実なので直す。

直し方は既存の規約どおり「**月初へ寄せてから足し引きする**」（前例: `app/Http/Controllers/Zeal/DashboardController.php:130-132`・
`app/Services/ScheduleBoardService.php:148-155`・`app/Services/ScheduleCardService.php:141-147`）。

## 走査の結果（2026-09-23 実測・親セッション）

アプリ（`app/` `resources/`）の月の足し引きは **22 件**。直すのは **5 行（4 ファイル）**、残り **17 件**は起点が
`startOfMonth()` か `Carbon::create(年, 月, 1)` なので安全（1 件ずつ起点を読んで確かめた）。

| # | ファイル:行（`e6787c20` 時点） | 今 | 症状（今日 = 2026-03-31 の実測） |
|---|---|---|---|
| 1 | `app/Http/Controllers/DashboardController.php:106` | `JapanTime::today()->subMonth()->month . '月実績'` | 「3月実績」（正は 2月） |
| 2 | `app/Http/Controllers/DashboardController.php:855` | `$prevMonth = JapanTime::today()->subMonth();` | 3 月の未確定の契約を「前月」として集計する |
| 3 | `app/Http/Controllers/Zeal/DashboardController.php:42` | `$lastMonth = $now->copy()->subMonth();` | 前月の入会・退会が当月と同じ値になり差分が 0 |
| 4 | `resources/views/admin/master/zeal-simulation-categories/_form.blade.php:112` | `\App\Support\JapanTime::today()->addMonth()->format('Y-m')` | 「来月」の既定が 2026-05（正は 2026-04）＝ 1 か月飛ばし、その月の既存セルが自動反映から漏れる |
| 5 | `app/Http/Controllers/Zeal/InquiryController.php:56` | `JapanTime::today()->subMonths($i)->format('Y-m')`（ループの中） | 18 か月のうち重複 7 件・実際に出る月は 11 種 |

⚠ 1・2 は同じ「前月」を 2 か所で計算している（ラベルと集計）。**必ず同時に直す**（片方だけだと Bug #41 の型）。

### 溢れる日（2026 年の全 365 日を掃引した実測）

| 形 | 溢れる日 |
|---|---|
| 前月（`subMonth()`。#1〜#3） | **7 日**: 3/29・3/30・3/31・5/31・7/31・10/31・12/31（閏年の 2028 年は 3/29 が抜けて 6 日） |
| 来月（`addMonth()`。#4） | **7 日**: 1/29・1/30・1/31・3/31・5/31・8/31・10/31（2028 年は 6 日） |
| 過去 18 か月の一覧（#5） | **29 日**（2028 年は 25 日） |

⚠ 引き継ぎメモの「月末の 5 日」は 3/29・3/30 と来月側を数えていなかった（直し方は変わらない）。
⚠ **月末でも溢れない日がある**: 8/31 の前月（7/31 がある）・3/15 や 9/4 のような月の途中はどちらの順序でも同じ値になる。
回帰テストの「今日」は **2026-03-31**（前月・来月・18 か月の 3 形とも溢れる日）に固定する。

### 範囲外（見つけたが今回は直さない）

- 日付ピッカーの「1ヶ月前」ボタン（`setMonthAgo`。9 ビュー）が JS の `setMonth(getMonth() - 1)` で同じ形に溢れる
  （3/31 に押すと 3/3 が選ばれる。node で実測）。**ブラウザのローカル日**を使うので Bug #61 の改修で露出は変わっていない。
  直し方も別（月初へ寄せるのではなく「前月の末日で止める」）なので別の作業にする
- `database/seeders/InquirySampleSeeder.php` の `strtotime('+6 months')` など 4 件（開発用のサンプルデータ）

---

## Task 1: 5 行を直し、今日を 3/31 に固定した回帰テストで守る（サブエージェント）

### ファイル

- 変更: `app/Http/Controllers/DashboardController.php`（#1・#2）
- 変更: `app/Http/Controllers/Zeal/DashboardController.php`（#3）
- 変更: `app/Http/Controllers/Zeal/InquiryController.php`（#5）
- 変更: `resources/views/admin/master/zeal-simulation-categories/_form.blade.php`（#4。**112 行目の 1 行だけ**）
- 新規: `tests/Feature/MonthEndOverflowTest.php`

これ以外のファイルは変えない。

### 直し方（この形にする）

- #1: `$previousMonthLabel = JapanTime::today()->startOfMonth()->subMonth()->month . '月実績';`
- #2: `$prevMonth      = JapanTime::today()->startOfMonth()->subMonth();`（続く `$prevMonthStart` / `$prevMonthEnd` の 2 行はそのまま）
- #3: `$lastMonth    = $now->copy()->startOfMonth()->subMonth();`
  ⚠ `$now` は `compact('now')` でビューへ渡り「〇年〇月〇日 時点」に出る。**`$now` 自体を書き換えない**（必ず `copy()` が先）。
  `JapanTime::today()` は可変の Carbon を返す
- #4: `$defaultApplyFrom = \App\Support\JapanTime::today()->startOfMonth()->addMonth()->format('Y-m'); // デフォルト: 来月`
  ⚠ **112 行目の 1 行のまま**にし、行末の `// デフォルト: 来月` もそのまま残す（`tests/Feature/ClockReadScanTest.php` の docblock が
  この行をビューの行中 `//` の near-miss として行番号つきで名指ししている）。Blade に行を足さない・Blade ディレクティブを作らない（Bug #30）
- #5: ループの外で 1 回だけ起点を作る:
  ```php
          $months = collect();
          $base   = JapanTime::today()->startOfMonth();
          for ($i = 0; $i < 18; $i++) {
              $months->push($base->copy()->subMonths($i)->format('Y-m'));
          }
  ```

PHP の 3 ファイルには、前例（`Zeal/DashboardController.php:129-131`）と同じ調子の短いコメントを足してよい
（なぜ月初へ寄せるのか。#1・#2 には「ラベルと集計が同じ前月を指す。片方だけ直さない」も）。
⚠ コメントに `now(` `today(` `date(` という文字列を書かない（走査テストの検出器に当たりうる）。

### 回帰テスト（`tests/Feature/MonthEndOverflowTest.php`）

- `namespace Tests\Feature;` / `class MonthEndOverflowTest extends TestCase` / `use RefreshDatabase;`
  ＋ 必要な trait（`Tests\Concerns\CreatesZealSchema` / `CreatesZealSimulationSchema` / `ParsesForms`）
- `setUp()` で `Carbon::setTestNow(Carbon::parse('2026-03-31 03:00:00', 'UTC')); // 日本時間 3/31 12:00`
  （`use Illuminate\Support\Carbon;`。`tests/Feature/JapanBusinessDayTest.php` と同じ書き方）、`tearDown()` で `Carbon::setTestNow();`
- クラスの docblock に罠を書く: Carbon の溢れ ／ **「今日」を月末以外（や 8/31 のように前月に同じ日がある月末）にすると
  正しい順序と誤った順序が同じ値になり、修正を元に戻してもこのテストは緑のまま**（BACKLOG 2026-09-04 の節で実測済み）
- **自己検査**: private メソッドで、凍結した「今日」について
  `today->copy()->subMonth()` と `today->copy()->startOfMonth()->subMonth()` の年月が**違う**こと、
  `addMonth()` の側も**違う**ことを `assertNotSame` する（失敗メッセージに理由）。**4 本のテストすべての先頭で呼ぶ**
  （日付を差し替えた瞬間に落ちて気づけるように。前例: `tests/Feature/Schedule/ScheduleBoardTest.php:499-513`）
- **期待値は文字列・数値のリテラルで書く。** Carbon で組み立てない（実装と同じ式で組むと同義反復になる。Bug #61 の ②）

#### テスト 1: テナントダッシュボード（#1 と #2 を 1 本で。Bug #41）

- データ（`tests/Feature/Tenant/UnitLabelDisplayTest.php` の setUp の作り方にならう）:
  - `Customer` 1 件、`Property` 1 件（`department` `tenant` / `property_type` `tenant` / `operation_status` `active` を明示）
  - 区画 3 つ（1 階 A・B・C。`display_name` は `Unit::generateDisplayName()`）
  - 契約 3 件（`rent` だけ入れ、ほかの料金は入れない。`rent_start_date` は `contract_date` と同じ）:
    - K1: 区画 A、契約日 2026-01-10、解約日 2026-03-15、`status` `terminated`、`rent` 100000
    - K2: 区画 B、契約日 2026-03-05、解約日なし、`status` `active`、`rent` 30000
    - K3: 区画 C、契約日 2026-03-10、解約日なし、`status` `active`、`rent` 20000
- `must_change_password` false の manager で `GET /dashboard/tenant` → 200
- アサート:
  - `viewData('previousMonthLabel')` が `'2月実績'`
  - HTML に `<span class="section-label">2月実績</span>`（⚠ タグごと見る。「5〜2月」など別の場所の「2月」に当たらないように）
  - `viewData('buildings')->all()` が `[['id' => 物件の id, 'name' => 物件名, 'monthly_income' => 100000, 'occupancy_rate' => 33.3]]`
    （誤った順序だと 3 月を集計して 150000 / 66.7 になる）

#### テスト 2: ZEAL ダッシュボード（#3）

- `createZealSchema()`、`$this->seed(DepartmentSeeder::class)`、体験予約の接続の向け直し（下記）
- 会員 6 人（`store_id` 1・`created_by` は作った利用者の id でよい）:
  - 入会 2026-02-10 / 2026-02-20 / 2026-03-05（退会なし）
  - 入会 2025-06-01・退会 2026-02-15 ／ 入会 2025-06-01・退会 2026-02-25 ／ 入会 2025-06-01・退会 2026-03-10
- zeal 部署に属する executive（`must_change_password` false。`tests/Feature/Zeal/SimulationValidationFeedbackTest.php` の `actor()` と同じ作り方）で `GET /zeal` → 200
- アサート（viewData）: `joinedThisMonth` 1 / `joinedLastMonth` 2 / `joinDiff` -1 / `withdrewThisMonth` 1 / `withdrewLastMonth` 2 / `withdrawDiff` -1
  （誤った順序だと先月が 3 月になり 1 / 0 / 1 / 0）
- アサート（HTML）: `2026年3月31日 時点`（`$now` を書き換えていないこと。書き換えると `2026年2月1日` になる）

#### テスト 3: 経営試算表の項目マスタの編集画面（#4）

- `createZealSimulationSchema()`、`seedZealSimulationCategories()`（`code` `rent` が計算タイプ「固定額」）
- executive（`must_change_password` false。この画面は `role:executive` だけで部署は要らない）で
  `GET route('admin.master.zeal-simulation-categories.edit', $rent)` → 200
- `parseForm($html, 'action="' . route('admin.master.zeal-simulation-categories.update', $rent) . '"')` の
  `fields['apply_from_month']` が `'2026-04'`（誤った順序だと `'2026-05'`）

#### テスト 4: ZEAL 体験予約一覧の月の絞り込み（#5）

- `$this->seed(DepartmentSeeder::class)`、体験予約の接続の向け直し（下記）
- zeal 部署に属する利用者で `GET /zeal/inquiries` → 200
- HTML の `<select name="month" …>` の中の `<option value="…">` を順に取り出し、次と**完全一致**:
  `['', '2026-03', '2026-02', '2026-01', '2025-12', '2025-11', '2025-10', '2025-09', '2025-08', '2025-07', '2025-06', '2025-05', '2025-04', '2025-03', '2025-02', '2025-01', '2024-12', '2024-11', '2024-10']`
  （先頭の `''` は「月: すべて」。誤った順序だと重複 7 件・欠落 7 件）

#### 体験予約の接続の向け直し（テスト 2・4 で使う private メソッド）

`App\Models\GymInquiry` は外部 DB（`'zeal'` 接続・MySQL）を読む。テストでは SQLite のメモリ DB へ向け直し、
一覧の問い合わせが使う列だけの `gym_inquiries` を作る（**正本の DDL はリポジトリに無い**＝外部の同期側が持つ。
docblock にそう書く）:

```php
config(['database.connections.zeal' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
]]);
DB::purge('zeal');
Schema::connection('zeal')->create('gym_inquiries', function (Blueprint $t) {
    $t->id();
    $t->string('name', 100)->nullable();
    $t->string('status', 30)->nullable();
    $t->date('inquiry_date')->nullable();
});
```

⚠ 向け直さないと、テストが手元の MySQL（127.0.0.1:3306）へ接続しに行く。

### 手順（TDD）

1. テストファイルだけを書く
2. `--filter MonthEndOverflowTest` を流し、**4 本とも赤**になることを確かめる。赤の理由が溢れそのもの
   （例: `'3月実績'` と `'2月実績'` の食い違い、`'2026-05'` と `'2026-04'`）であることを失敗文で確かめる。
   テーブルが無い・500 など**別の理由で赤なら、テストを直してから**進む
3. 5 行を直す
4. `--filter MonthEndOverflowTest` が 4 本とも緑 → 全件を流して緑（1752 本 + 4 本）
5. コミットを 2 つに分ける（どちらの時点でも全件が緑になる順）:
   - `fix:` … アプリの 4 ファイルだけ
   - `test:` … `tests/Feature/MonthEndOverflowTest.php` だけ

---

## Task 2: 変異テストと最終確認（親セッション）

- docs/RULES.md Bug #44 の作法（先にコミット → 各変異の前に `git status --porcelain` が空 → 出現がちょうど 1 回のときだけ当てる →
  `git diff --stat` で着弾を確認 → 全件を流す → 落ちたテストと理由の文言を記録 → `git checkout -- <file>` で戻す → 空を再確認）
- 予定する変異: 5 行それぞれを元の順序へ戻す ／ #1 だけ・#2 だけを戻す（1 本のテストが両方を守っているか）／
  #3 の `copy()` を落とす ／ #5 をループの中の `JapanTime::today()` へ戻す ／ 自己検査を外して「今日」を 3/15 にする ／
  カナリア（確実に赤くなる変異）
- コンパイル済みビューを `php -l`（基準 269 本 / INVALID 0 件）
- 全件（基準 1752 本 → 1756 本の見込み）

## Task 3: 記録（親セッション）

- docs/RULES.md に Bug #62、CLAUDE.md の Top traps に 1 行、docs/BACKLOG.md の「⚠ 利用者の判断を仰ぐ: 月末の溢れ 3 か所」を完了へ
- この計画の末尾に「実測記録」（変異テストの表・最終確認の結果）
