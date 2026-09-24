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

PHP（Carbon）の月の足し引きは `app/` `resources/` で **22 件**（JS の `setMonth` 7 件は別）。直すのは **5 行（4 ファイル）**、残り **17 件**は起点が
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
  直し方も別（月初へ寄せるのではなく、たとえば「前月の末日で止める」。仕様は利用者に確認する）なので別の作業にする
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

---

## 実測記録（2026-09-23）

### コミット

| コミット | 内容 |
|---|---|
| `f0cdccdc` | docs: この計画 |
| `635001d1` | fix: 5 行を月初起点にする（アプリ 4 ファイル） |
| `56155a4b` | test: 回帰テスト 4 本（`tests/Feature/MonthEndOverflowTest.php`） |
| `2eec67a6` | docs: コメントで理由と書き方を分ける（レビューの Minor） |
| `aea1081e` | test: 月のラベル・体験予約の接続・実績期間も見る（レビューの Minor と任意の提案） |

### 直す前の赤（TDD）

- テスト 1: ラベルが `'3月実績'`。#1 だけを先に直して流し直し、集計が 150,000 円・66.7% で赤になることも確かめてから #2 を直した
- テスト 2: `joinedLastMonth` が 1
- テスト 3: 適用開始月の既定が `'2026-05'`
- テスト 4: 重複 7（2026-03・2025-12・2025-10・2025-07・2025-05・2025-03・2024-12）・欠落 7（2026-02・2025-11・2025-09・2025-06・2025-04・2025-02・2024-11）

別の理由（表が無い・500・認可）の赤は無かった。

### レビュー

- 仕様適合: 指摘なし（触ってはいけない 17 件に手が入っていないことも確認）
- コード品質: Critical / Important なし。Minor 3 点（体験予約の月のラベルも見る・向け直しが効いたことを見る・InquiryController のコメントが理由を取り違えて読める）と任意の提案 1 点（同じ画面の実績期間）を取り込み、再レビューで承認
- 据え置き: 「月末日（31日など）」の言い回し（前例と同じ）・「溢れて」と「オーバーフローして」の混在

### 変異テスト（17 通り。docs/RULES.md Bug #44 の作法）

実行役 `scratchpad/mutate.py`（各変異の前後に作業ツリーが空・置換対象がちょうど 1 回・`git diff --stat` で着弾・全件を `--log-junit` で流す・`finally` で戻す）。

| ID | 変異 | 期待 | 結果 |
|---|---|---|---|
| M00 | カナリア: ラベルの接尾辞を変える | テスト 1 だけ | ✅ テスト 1「ラベルが前月（2 月）になっていない」 |
| M01 | #1 ラベルを元の順序へ | テスト 1（ラベル） | ✅ 同上 |
| M02 | #2 集計を元の順序へ | テスト 1（集計） | ✅ テスト 1「集計が前月（2 月）になっていない（3 月を集計すると 150,000 円・66.7%）」。ラベルのアサートは通過 |
| M03 | #3 先月を元の順序へ | テスト 2（先月） | ✅ テスト 2「先月（2 月）の入会になっていない」（1 ≠ 2） |
| M04 | #3 `copy()` を落とす | テスト 2（時点） | ✅ テスト 2 の `2026年3月31日 時点`（画面は `2026年2月1日 時点`。JUnit で 174 行と確認） |
| M05 | #4 来月を元の順序へ | テスト 3 | ✅ テスト 3「既定の適用開始月が来月（4 月）になっていない」 |
| M06 | #5 ループの中で毎回今日から引く | テスト 4（value） | ✅ テスト 4「月の選択肢が当月から 18 か月連続になっていない」 |
| M07 | #5 `copy()` を落として累積で引く | テスト 4 | ✅ 同上 |
| M08 | #5 起点の `startOfMonth()` を落とす | テスト 4 | ✅ 同上 |
| M09 | テストの今日を 3/15 | 4 本とも自己検査（前月側） | ✅ 4 本とも「この『今日』では前月の求め方 2 通りが同じ値になる…」 |
| M10 | 自己検査を外し、今日を 3/15 にし、#1 を戻す | テスト 1・3・4 は緑／テスト 2 は時点で赤 | ✅ テスト 2 だけ（画面は `2026年3月15日 時点`）。**#1 を戻してもテスト 1 は緑 ＝ 自己検査が load-bearing** |
| M11 | 等価: 集計の `$prevMonthStart` の `startOfMonth()` を落とす | 全件緑 | ✅ `OK (1756 tests, 10807 assertions)`（`$prevMonth` がすでに月初） |
| M12 | テストの今日を 7/31 | 4 本とも自己検査（来月側） | ✅ 4 本とも「この『今日』では来月の求め方 2 通りが同じ値になる…」（前月側は 7/31 でも溢れるので通過） |
| M13 | 実績期間の起点から `startOfMonth()` を落とす | テスト 1（実績期間） | ✅ テスト 1「全体カードの実績期間の終わりが前月（2 月）になっていない」 |
| M14 | 体験予約一覧の月のラベルを `createFromFormat('Y-m', $m)` にする | テスト 4（ラベル） | ✅ テスト 4「月の選択肢のラベルが…」＋ `ClockReadScanTest::test_views_never_read_the_clock` — **2 つの守り手が独立に止める** |
| M15 | 体験予約の表だけを作らない（接続は SQLite のメモリのまま） | テスト 2（trialCount）・テスト 4（500） | ✅ テスト 2「…null なら向け直しが効かず…」（null ≠ 0）・テスト 4「Expected response status code [200] but received 500」 |
| M16 | 実績期間の終わりだけを今日から直接引く | テスト 1（実績期間） | ✅ 同 M13 |

- M10 の期待は、最初「全件緑」と書いていたのを、実行前にコード品質レビューの指摘で「テスト 2 だけが時点の文字列で赤」に直した（3/15 に差し替えると `2026年3月31日 時点` のリテラルが合わなくなるため。溢れの検出ではない）
- M04・M10 は失敗文の先頭が画面の HTML になる（`assertSee` が画面を出す）ので、JUnit の中身で 174 行のアサートと画面の日付を確かめた
- 向け直しそのもの（`pointGymInquiriesAtMemory()`）を外す変異は、手元の MySQL へ接続しに行くので当てていない。代わりに M15（表だけを作らない）で `trialCount` の網を測った

### 最終確認

- 全件 `OK (1756 tests, 10807 assertions)`（基準 1752 本・10775 から +4 本・+32）
- コンパイル済みビュー **269 本 / INVALID 0 件**
- 作業ツリーは空

### 計画からの訂正

- 「前例: `Zeal/DashboardController.php:130-132`」は修正前（`e6787c20`）の行番号。修正で上に 3 行足したので、今は 132-138 行（理由のコメント 132-133・起点 135・ループ 136-138）。計画の中でも 129-131 と 130-132 の 2 通りで書いていた
- 「`setMonthAgo`。9 ビュー」は正確には「定義 7 か所・参照 9 ビュー（ボタン 8 か所）」
- 引き継ぎメモと BACKLOG の「3/31・5/31・7/31・10/31・12/31 の 5 日で 15/24 → 24/24」は、1 時間ごとの掃引で **3/29**・5/31・7/31・10/31・12/31 だった（3/30・3/31 は日本時間化の前から終日溢れていた）。前月の誤りの時間は年間 186 h（日本時間化の前）→ 168 h（後）→ 0 h（今回）
