# 買主の画面で押せないボタンを出さない Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 買主の画面（住宅事業・不動産で共用の一覧と詳細）で、ルートが断る人にはボタンとリンクを出さない（押すと 403 になる形をなくす。Bug #96 と同じ形）。

**Architecture:** ビューの先頭の `@php` でルートと同じ判定（`isManagerOrAbove()`・`isExecutive()`）を 1 回作り、`@if` で出し分ける（`buyers/index` の今の `$canChangeRank` と同じ形）。ルート・ミドルウェア・コントローラは変えない。テストは両部署 × 3 役割で、画面のボタン（送り先の URL まで）の有無を表で見て、出たリンクは描いた href をたどって 200 を見る。

**Tech Stack:** Laravel 12 / Blade / PHPUnit 11（`#[DataProvider]`）

**Spec:** `docs/superpowers/specs/2026-11-27-buyer-screen-buttons-design.md`（作業場所 `.claude/worktrees/buyer-buttons`・ブランチ `buyer-buttons`・`1f1ed776`）

## Context

買主の画面は役割を見ずに全員へボタンを出している。ルートは役割で断るので、押せない人が押すと日本語の 403 の画面になる。2026-11-26 にランクのバッジだけ直し（Bug #137・#96 の追記）、その最後の点検で残りのボタンを範囲外として名指しした。利用者が設計メモを承認し（2026-11-27）、アンケートの行の「詳細」は案 A（一般担当には出さない）と決めた。

## Global Constraints

- ルート・ミドルウェア・コントローラ・DB は変えない。変えるのはビュー 2 本（`resources/views/buyers/index.blade.php`・`show.blade.php`）とテストだけ
- 判定はルートと同じ: 経営層・管理者 ＝ `auth()->user()->isManagerOrAbove()`、経営層 ＝ `auth()->user()->isExecutive()`
- 経営層でない人の案内の文（設計メモのまま。Blade には `&gt;` をそのまま書く）:
  「ℹ️ 不動産事業のアンケートは現在未設定です。経営層がマスタ管理 &gt; アンケート設問管理から設問を追加すると、顧客登録時にアンケートが利用可能になります。」
- 経営層の案内は今の文とリンクのまま（文言・リンク先・style を変えない）
- 「顧客一覧に戻る」・一覧の行の「詳細」・ランクのバッジ・アンケートの行の日付・分譲地・担当は変えない
- `@else` の直後に文字を続けない（Bug #6）。案内の 2 つの分岐は、それぞれ文全体を 1 行で書く（改行を挟むと「です。」の後ろに空白が出る）
- テストは `#[DataProvider]`（`@dataProvider` の doc-comment は使わない）。全件は `--display-phpunit-deprecations` 付き
- 新しい Bug 番号は足さず、RULES の Bug #96 に追記する（記録の直前に最新の 13.x で最後の番号を数える。2026-11-27 時点は #151）
- git stash を使わない。複雑なシェルは scratchpad のスクリプトにして絶対パスで起動する

## Review Focus

1. 一般担当の詳細画面のアンケートの行 — ボタンが 1 つも無くても、日付・分譲地・担当はそのまま出る（Task 1 のテストで日付 `2026/10/01` を見る）
2. 管理者 — 「編集」「アンケートを追加」「詳細」はあり、「削除」（顧客・アンケートとも）は無い（Task 1 の DataProvider の管理者の行）
3. 住宅事業の一覧 — 不動産の案内はどの役割にも出ない（Task 2 のテストで見る）
4. 不動産に有効な設問が 1 つでもあれば、案内はどの役割にも出ない（Task 2 のテストで見る）
5. 経営層 — 描いた削除のフォームを送ると今どおり消える（既存の `test_a_customer_is_removed_from_the_department_from_the_detail_screen`・`test_a_survey_is_registered_edited_and_deleted_from_the_screens`〈両部署〉が描いたフォームを送っている。緑のままを Task 3 の全件で見る）

## File Structure

| ファイル | 役割 |
|---|---|
| `tests/Concerns/ChecksBuyerScreenButtons.php`（新規） | 買主の画面のボタンの表と、その有無・リンクのたどりを見るアサート（両部署のテストで共用。`ChecksRankMenu` と同じ置き方） |
| `tests/Feature/Housing/Screens/CustomerScreensTest.php` | 住宅事業 × 3 役割のテスト・住宅事業の一覧に案内が出ないこと |
| `tests/Feature/RealEstate/Screens/CustomerScreensTest.php` | 不動産 × 3 役割のテスト・案内の出し分け |
| `resources/views/buyers/show.blade.php` | 詳細のボタン 6 種の出し分け |
| `resources/views/buyers/index.blade.php` | 一覧の「新規登録」と不動産の案内の出し分け |

---

### Task 0: 作業場所の用意

- [ ] **Step 1:** 作業場所の cwd で `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`（`.env` は作らない）。`vendor/bin/phpunit` があること
- [ ] **Step 2:** scratchpad に `run-tests.sh`（作業場所へ cd し、`APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations "$@"`）を書き、絶対パスで起動する
- [ ] **Step 3:** 着手前の全件を流し、本数と assertions を記録する（期待: 13.x と同じ `OK (5716 tests, …)` 前後・警告 0）

### Task 1: 一覧の「新規登録」と詳細のボタンを、押せる人にだけ出す

**Files:**
- Create: `tests/Concerns/ChecksBuyerScreenButtons.php`
- Modify: `resources/views/buyers/show.blade.php`（先頭の `@php`・6 か所の `@if`）
- Modify: `resources/views/buyers/index.blade.php:8-20`（`@php` に 1 行・「新規登録」の `@if`）
- Test: `tests/Feature/Housing/Screens/CustomerScreensTest.php`・`tests/Feature/RealEstate/Screens/CustomerScreensTest.php`

**Interfaces（トレイト `Tests\Concerns\ChecksBuyerScreenButtons`。使う側は `$this->user` を持ち、`SubmitsScreenForms` と一緒に使う）:**
- `protected function buyerScreenButtons(string $department, Buyer $buyer, BuyerSurvey $survey): array` — 下の 9 行の表を返す。1 行は `['page' => 'index'|'show', 'type' => 'link'|'delete', 'label' => string, 'url' => string, 'who' => 'everyone'|'manager'|'executive']`

  | page | type | label | url | who |
  |---|---|---|---|---|
  | index | link | 新規登録 | `{d}.customers.create` | manager |
  | index | link | 詳細 | `{d}.customers.show` | everyone |
  | show | link | 顧客一覧に戻る | `{d}.customers.index` | everyone |
  | show | link | 編集 | `{d}.customers.edit` | manager |
  | show | delete | 削除 | `{d}.customers.destroy` | executive |
  | show | link | アンケートを追加 | `{d}.customers.surveys.create` | manager |
  | show | link | 詳細 | `{d}.customers.surveys.edit` | manager |
  | show | link | 編集 | `{d}.customers.surveys.edit` | manager |
  | show | delete | 削除 | `{d}.customers.surveys.destroy` | executive |

- `protected function assertBuyerScreenButtons(string $indexHtml, string $showHtml, array $buttons, UserRole $role): void` — 行ごとに、使える（everyone ＝ 常に・manager ＝ `$role->isManagerOrAbove()`・executive ＝ `$role === UserRole::Executive`）なら 1 個、使えないなら 0 個:
  - link: その画面の `<a …href="URL"…>…</a>` のうち、中身の文字（`strip_tags` して `trim`）が label のものの数。失敗の文 `"[{$role->value}] {$page} の「{$label}」のリンク（{$url}）が {$n} 個ではない"`
  - delete: その画面の `<form …action="URL"…>…</form>` のうち、中に `name="_method" value="DELETE"` と文字が label の `<button>` を持つものの数。失敗の文 `"[{$role->value}] {$page} の「{$label}」の削除のフォーム（{$url}）が {$n} 個ではない"`
  - 使えない行は、加えて その画面に `href="URL"`（link）／`action="URL"`（delete）が 1 つも無いこと。失敗の文 `"[{$role->value}] {$page} に使えない人への {$url} が残っている"`
  - URL は `e()` してから `preg_quote`（閉じの `"` まで含めて当てる＝`…/customers/1` が `…/customers/1/edit` に当たらない）
- `protected function assertUsableLinksOpen(array $buttons, UserRole $role): void` — 使える link 行の url を `$this->actingAs($this->user)->get()` して、状態が 200（Bug #47）。失敗の文 `"[{$role->value}] 「{$label}」のリンク（{$url}）を開けない"`

- [ ] **Step 1: トレイトと、落ちるテストを書く**

両テストクラスに `use ChecksBuyerScreenButtons;` と、同じ形の 1 本と DataProvider を足す（住宅事業は `$this->member($role)`、不動産は `$this->userWithRole($role)`）:

```php
#[DataProvider('buttonRoles')]
public function test_each_role_sees_only_the_buttons_it_can_use(UserRole $role): void
{
    $this->user = $this->member($role);   // 不動産は userWithRole($role)
    $buyer = $this->buyer();
    $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'housing', 'survey_date' => '2026-10-01']);
    $buttons = $this->buyerScreenButtons('housing', $buyer, $survey);
    $show = $this->htmlOf(route('housing.customers.show', $buyer));

    $this->assertBuyerScreenButtons($this->htmlOf(route('housing.customers.index')), $show, $buttons, $role);
    $this->assertUsableLinksOpen($buttons, $role);
    $this->assertStringContainsString('2026/10/01', $show, 'アンケートの行の日付が出ていない');   // Review Focus 1
}

public static function buttonRoles(): array
{
    return ['経営層' => [UserRole::Executive], '管理者' => [UserRole::Manager], '一般担当' => [UserRole::Staff]];
}
```

- [ ] **Step 2: 赤を確かめる** — `run-tests.sh --filter test_each_role_sees_only_the_buttons_it_can_use tests/Feature/Housing/Screens/CustomerScreensTest.php tests/Feature/RealEstate/Screens/CustomerScreensTest.php`。期待: 経営層の 2 本は緑（今は全員に出している）、管理者の 2 本は「削除の削除のフォーム（…/customers/1）が 0 個ではない」、一般担当の 2 本は「新規登録のリンク（…/create）が 0 個ではない」で赤（理由の文まで見る）

- [ ] **Step 3: ビューを直す**
  - `show.blade.php`: `@section('content')` の直後に注記（`{{-- --}}`。ルートの役割・設計メモの名前）と `@php $canManage = auth()->user()->isManagerOrAbove(); $canDelete = auth()->user()->isExecutive(); @endphp`。「編集」「アンケートを追加」「アンケートの行の詳細・編集」を `@if($canManage)`、顧客の削除のフォームとアンケートの削除のフォームを `@if($canDelete)` で包む。ボタンの入れ物の `div` は残す（空でもよい）
  - `index.blade.php`: 先頭の `@php` に `$canRegister = auth()->user()->isManagerOrAbove();` を足し、「新規登録」の `<a>` を `@if($canRegister)` で包む。注記に「新規登録は role:executive,manager」を 1 行足す。`$canChangeRank` は変えない

- [ ] **Step 4: 緑を確かめる** — Step 2 と同じコマンドで 6 本とも緑。続けて両テストファイル全体を流して緑（既存の削除の往復も通る）

- [ ] **Step 5: コミット**

```bash
git add tests/Concerns/ChecksBuyerScreenButtons.php tests/Feature/Housing/Screens/CustomerScreensTest.php tests/Feature/RealEstate/Screens/CustomerScreensTest.php resources/views/buyers/show.blade.php resources/views/buyers/index.blade.php
git commit -m "fix: 買主の画面で、押せない人に新規登録・編集・削除・アンケートのボタンを出さない"
```
（本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`）

### Task 2: 不動産の案内の設問管理のリンクを経営層にだけ出す

**Files:**
- Modify: `resources/views/buyers/index.blade.php:120-129`（案内の分岐）
- Test: `tests/Feature/RealEstate/Screens/CustomerScreensTest.php`・`tests/Feature/Housing/Screens/CustomerScreensTest.php`

**Interfaces:** Task 1 の `buttonRoles()`（両クラス）を使う。

- [ ] **Step 1: 落ちるテストを書く**

不動産に `test_the_survey_setup_notice_links_to_the_master_only_for_executives(UserRole $role)`（`#[DataProvider('buttonRoles')]`）:
- `$this->user = $this->userWithRole($role)`・`$this->buyer()`。有効な設問を作らずに一覧を描く
- `$link` ＝ `/ℹ️ 不動産事業のアンケートは現在未設定です。<a href="{admin.survey-questions.index ?department=realestate}"[^>]*>マスタ管理 &gt; アンケート設問管理<\/a>から設問を追加すると、顧客登録時にアンケートが利用可能になります。/u`、`$plain` ＝ Global Constraints の文
- 経営層: `$link` が 1 回（「経営層の一覧に設問管理へのリンクつきの案内が無い」）・`$plain` が 0 回（「経営層の一覧に文だけの案内も出ている」）・admin の URL を GET して 200
- ほか: `$plain` が 1 回（「経営層でない人の一覧に文だけの案内が無い」）・`href="{admin の URL}"` が 0 回（「経営層でない人の一覧に設問管理へのリンクがある」）
- そのあと不動産の有効な設問を 1 つ作り（`SurveyQuestion::create([... 'department' => 'realestate', 'is_active' => true])`）、一覧を描き直して `現在未設定です` が 0 回（「有効な設問があるのに案内が出ている」。Review Focus 4）

住宅事業に `test_the_housing_list_has_no_realestate_notice(UserRole $role)`（`#[DataProvider('buttonRoles')]`）: `$this->member($role)` で住宅事業の一覧を描き、`現在未設定です` が 0 回（「住宅事業の一覧に不動産の案内が出ている」。Review Focus 3）

- [ ] **Step 2: 赤を確かめる** — 不動産の管理者・一般担当の 2 本が「経営層でない人の一覧に文だけの案内が無い」で赤。不動産の経営層と住宅事業の 3 本（計 4 本）は緑（今と同じ動きを守るテスト）
- [ ] **Step 3: ビューを直す** — 先頭の `@php` に `$canEditQuestions = auth()->user()->isExecutive();`（注記: admin の門は role:executive）。案内の `div` の中を、`@if($canEditQuestions)` ＝ 今の 1 行（リンクつき）／`@else` ＝ 経営層でない人の文の 1 行／`@endif` の複数行にする（`@if($department === 'realestate')` と `@if(!$hasRealestateQuestions)` は変えない）
- [ ] **Step 4: 緑を確かめる** — 両テストファイル全体が緑
- [ ] **Step 5: コミット** — `fix: 不動産の顧客一覧の案内の設問管理のリンクを経営層にだけ出す`（同じ締めの行）

### Task 3: 確かめる（全件・ビューの lint・変異・実ブラウザ・全体の点検）

- [ ] **Step 1: 全件** — `run-tests.sh`。期待: 着手前の本数 ＋ 足した本数（Task 1: 6・Task 2: 6）・警告 0・メモリのピークを記録
- [ ] **Step 2: コンパイル済みビューの lint** — scratchpad のスクリプトで `APP_KEY=… php artisan view:cache` → `storage/framework/views/*.php` をすべて `php -l` → `view:clear`。期待: INVALID 0（本数を記録）
- [ ] **Step 3: 変異**（docs/RULES.md Bug #44 の作法。先にコミット済み・1 通りずつ・`git diff --stat` 非空・`git checkout --` で戻して `git status --porcelain` 空・落ちたテストの組と理由の 1 行目まで突き合わせる）。実行役は main repo の `.claude/route-coverage-tools/mutate/mutate_sa403.py` を作業場所の `.claude/route-coverage-tools/mutate/mutate_bsb.py` へ写して直す（流す範囲は `tests/Feature/Housing/Screens/CustomerScreensTest.php`・`tests/Feature/RealEstate/Screens/CustomerScreensTest.php`）。置き換えの文字列は直したあとのビューから取り、各 1 か所だけに当たることを確かめる。流す前に期待を書く:

  | id | 変異 | 落ちる見込みのテストと理由 |
  |---|---|---|
  | C0 | 詳細の「顧客一覧に戻る」の文字を変える（カナリア） | 両部署の 3 役割（6 本）・「顧客一覧に戻る」のリンク … 1 個ではない |
  | B1 | 一覧の「新規登録」の `@if` を外す | 両部署の一般担当・新規登録 … 0 個ではない |
  | B2 | `$canRegister` を `isExecutive()` に | 両部署の管理者・新規登録 … 1 個ではない |
  | B3 | `$canManage` を `isExecutive()` に | 両部署の管理者・編集 … 1 個ではない |
  | B4 | `$canDelete` を `isManagerOrAbove()` に | 両部署の管理者・削除の削除のフォーム … 0 個ではない |
  | B5 | 顧客の「編集」の `@if` を外す | 両部署の一般担当・編集（customers.edit）… 0 個ではない |
  | B6 | 「アンケートを追加」の `@if` を外す | 両部署の一般担当・アンケートを追加 … 0 個ではない |
  | B7 | アンケートの行の「詳細」だけ `@if` の外へ出す | 両部署の一般担当・詳細（surveys.edit）… 0 個ではない |
  | B8 | 顧客の削除のフォームを `@if($canManage)` に | 両部署の管理者・削除（customers.destroy）… 0 個ではない |
  | B9 | アンケートの削除のフォームを `@if($canManage)` に | 両部署の管理者・削除（surveys.destroy）… 0 個ではない |
  | B10 | 案内を `@if(true)`（全員にリンク） | 不動産の管理者・一般担当・文だけの案内が無い |
  | B11 | 案内を `@if(false)`（全員に文だけ） | 不動産の経営層・リンクつきの案内が無い |
  | B12 | `!$hasRealestateQuestions` の条件を外す | 不動産の 3 役割・有効な設問があるのに案内が出ている |
  | B13 | `$department === 'realestate'` の条件を外す | 住宅事業の 3 役割・住宅事業の一覧に不動産の案内が出ている |

  期待と違ったら、表の誤りか穴かを確かめる（穴ならテストを足して当て直す）。結果は `results-buyer-screen-buttons.*`。作業場所を消す前に、実行役・表・結果を main repo の同じ場所へ写す
- [ ] **Step 4: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright・画面が見えている状態。作業場所に `public/build` が無ければ main repo のものを写す＝新しい Tailwind のクラスを足さないので同じ CSS）: 経営層・管理者・一般担当で住宅事業と不動産の一覧と詳細（アンケート 1 件の顧客）を開き、ボタンの有無が表のとおり・一般担当の詳細のアンケートの行に日付が出る・見出しの行がボタン無しでも崩れない・不動産の一覧の案内（有効な設問 0 件）が経営層はリンク・ほかは文・1440px と 375px で `main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 5: 全体の点検** — 別の担当（いちばん強いモデル・読み取りだけ）に `1f1ed776..HEAD` を見てもらい、直す指摘は直して範囲を絞って見直してもらう

### Task 4: 記録

- [ ] **Step 1:** `docs/RULES.md` の Bug #96 の行の末尾に「⚠ 同じ形（2026-11-27）: 買主の画面（共用）の 新規登録・編集・アンケートを追加・アンケートの詳細と編集が一般担当に、削除・アンケートの削除・不動産の案内の設問管理のリンクが一般担当と管理者に出ていた → ルートと同じ判定で出し分けた（`tests/Concerns/ChecksBuyerScreenButtons.php`・両部署の `CustomerScreensTest`。両部署 × 3 役割で送り先の URL まで見て、出たリンクはたどって 200）。」と変異の件数を足す（最後の番号は直前に数える）
- [ ] **Step 2:** `docs/BACKLOG.md` に節「買主の画面で押せないボタンを出さない（Bug #96 の追記）」（設計メモ・計画へのリンク・直す前と直したことの表・検証・範囲外〈ほかの画面の全件の洗い出し・見るだけのアンケートの詳細画面〉）。前の節「保存の Ajax の 403 …」の範囲外の買主の画面のボタンに「→ 2026-11-27 に直した」と、同じ節の「⚠ `origin/13.x` への push はしていない。」に「→ 2026-11-26 に push 済み（`e526485b` に含まれる）」を書き足す（引き継ぎの残り）。末尾の「バックログ完了状況」に 1 段落
- [ ] **Step 3:** コミット `docs: 買主の画面で押せないボタンを出さない直しを記録する（Bug #96 の追記）`

### Task 5: 本番反映（どの段も利用者の言葉で承認を取ってから）

- [ ] **Step 1:** main repo で `git merge-base --is-ancestor 13.x buyer-buttons` と `ListAgents` で、13.x が進んでいない・13.x を動かすほかの会話が無いことを確かめる（進んでいれば 13.x をこの枝へマージして全件を流し直す）
- [ ] **Step 2:** 本番の読み取り（`ssh … /bin/sh < 手順ファイル`）: 2 本のビューの md5 が今の 13.x と一致・`laravel.log` が Nov 18 01:45（3333 行）のまま
- [ ] **Step 3:** `ExitWorktree(keep)` → main repo の cwd で `git checkout 13.x && git merge --ff-only buyer-buttons` → `./deploy.sh`（DB 変更・新しい PHP クラスなし。`composer dump-autoload` も SQL も要らない）
- [ ] **Step 4:** 反映の後の読み取り: 2 本の md5・コンパイル済みビューの lint・`laravel.log`・`bootstrap/cache` の権限。外から（ログインしない）ログイン画面 200・顧客一覧はログイン画面へ 302
- [ ] **Step 5:** BACKLOG の節に「本番反映」の表を足してコミットし、13.x へ早送りする。push は利用者が「push して」と言ったときだけ

## Verification（まとめ）

- 新しいテスト 12 本（Task 1: 両部署 × 3 役割・Task 2: 両部署 × 3 役割）が直す前に狙った理由で赤・直したあと緑
- 全件 OK・警告 0・コンパイル済みビューの lint INVALID 0
- 変異 14 通り（カナリアを含む）が期待どおり
- 実ブラウザで 3 役割 × 2 部署の一覧と詳細・案内の出し分け・375px と 1440px
