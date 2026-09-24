# 決裁申請 段階1 — F1〜F8 の修正と本番反映 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (this plan recommends inline execution) or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 決裁申請 段階1（ブランチ `approval-phase1`）の実ブラウザ確認（段階1 計画の Task 16）で見つけた欠陥 F1〜F8 を直し、後続（`approval-followups`）と `13.x` を取り込んだうえで本番へ反映する（段階1 計画の Task 17）。

**Architecture:** 既存の worktree `.claude/worktrees/approval-phase1` に `approval-followups` → `13.x` の順で**マージ**する（リベースしない＝記録に書いたコミット番号を保つ）。13.x の走査テスト（日本時間）が決裁のコードで引っかかる 5 か所を直してから、F1〜F8 をテスト先行で 1 件ずつ直す。本番は **DB が先・`deploy.sh` が後**。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）/ Blade + Alpine.js 3 / PHPUnit（SQLite）/ Playwright（ローカルの実ブラウザ）

---

## Context

- 決裁申請 段階1 は実装とローカルの実ブラウザ確認まで済み、**本番未反映**。確認の途中で欠陥 F1〜F8 が見つかり「判断待ち」だった（記録: `docs/superpowers/plans/2026-09-16-approval-phase1.md` の「Task 16 の実測記録」）
- 2026-09-24 に利用者が「直してから本番へ出す（A）」を選び、F4・F7・F8 の直し方は推薦どおりに決まった
- ブランチの状態（2026-09-24 実測）: `approval-phase1` = `a1d9dd35`（13.x より 109 本先・37 本遅れ）／ `approval-followups` = `60d89f09`（phase1 から分岐。phase1 に無いコミット 5 本＝パンくずの「ホーム」の修正・サイドバーの死にファイル 3 本の削除・テスト・記録）／ `13.x` = origin = `4fa0911e`（本番のコードは `999729f2`。差は docs だけ）
- 取り込みで衝突するのは**記録のファイルだけ**（`docs/BACKLOG.md`・段階1 の計画書）。`CLAUDE.md` と `resources/views/admin/users/index.blade.php` は自動でマージできる（実測）
- 13.x の走査テスト（Bug #61）が決裁のコードで引っかかるのは **5 か所・2 テスト**（下の Task 3〜5）。うち 2 か所が F5・F6

## 決めたこと

| # | 症状 | 直し方 |
|---|---|---|
| F1 | 決裁のみの人がパスワードを変えると、決裁のホームに「決裁以外の画面は使えません。」が出て「パスワードを変更しました。」が出ない（初回ログインで全員が踏む） | 変更のあとは `/dashboard` を経由せず `route($user->homeRouteName())` へ直接戻す |
| F1 と同じ形（調査で追加） | ①パスワード変更の「キャンセル」の戻り先が `route('dashboard')`（差し戻された直後に使われる）②ログイン中に `/login` を開く（案内の QR・ブックマーク）③`/` を開く — どれも決裁のみの人に警告が出る | ①はその人のホームへ ②③は門番（`RestrictApprovalOnlyUsers`）が「ホームの別名」（`/` と `/dashboard`）だけ**警告なしで**決裁のホームへ送る（通すのではない＝許可の一覧は広げない） |
| F2 | CSV 取込の案内の「元の画面へ戻る」が 405 | 戻り先を**入口ごとに**決めて案内に渡す。フォームが GET の画面にある 4 入口は今までどおりリファラー（絞り込み・ページが残る）、CSV の確定は**取込の画面** |
| F3 | 部門の管理の「略称」が 1 文字ずつ縦に折り返す | 略称とアルファベットのセルに `whitespace-nowrap` |
| F4 | ドメインの行に「通知メールが届かなくなります」が常に出る | 行には中立な人数だけ。人数と「届かなくなります」は**削除の確認**へ（設計書 §5.8 の文言そのまま。0 人のときは確認の質問だけ） |
| F5 | 案内の発行日が日本時間 0:00〜8:59 に前日 | `JapanTime::today()` |
| F6 | 決裁の利用者の管理の最終ログインが UTC | `JapanTime::format()` |
| F7 | 新規登録・CSV の案内にも「通知メール: 送る 0 人／送らない 0 人」が出る | 件数は**再発行のときだけ**出す |
| F8 | 絞り込みを変えて「検索」を押さずに「絞り込んだ全員を再発行」を押すと、前回の条件が対象になる | プルダウンとチェックは**変えた瞬間に送る**（CLAUDE.md の即時フィルタ）。検索語は今までどおり「検索」ボタン／Enter |
| 取り込み | 13.x の走査テストが拾う残り 3 か所 | 保存の瞬間（再発行の日時）・期限（1 回限りの鍵）・記録（削除の日時をログに残す）は**理由つきで分類**。再発行の通知メールの日本時間の整形は `JapanTime::format()` へ寄せる |

**実行方式（推薦）:** この会話でインライン実行（superpowers:executing-plans）。修正が小さく互いに近いので、タスクごとにサブエージェントを立てるより往復が少ない。代わりに Task 12 で独立したレビューを 1 回回し、Task 13 の変異テストで守りの実効を測る。

**本番反映（Task 16〜17）は、この計画の承認とは別に、その時点で本文で確認してから行う**（本番の読み取り・DB の変更・デプロイ・本番の死にファイル 3 本の削除）。`origin` への push は指示があったときだけ。

## 変えるファイル

| 区分 | ファイル |
|---|---|
| アプリ | `app/Http/Controllers/Auth/PasswordController.php` ／ `app/Http/Middleware/RestrictApprovalOnlyUsers.php` ／ `app/Support/Approval/LoginGuide.php` ／ `app/Support/Approval/ReissueResult.php` ／ `app/Http/Controllers/Admin/UserController.php`（2 行）／ `app/Http/Controllers/Approval/UserController.php`（2 行）／ `app/Http/Controllers/Approval/UserImportController.php`（1 行）／ `app/Mail/PasswordReissuedMail.php` |
| ビュー | `resources/views/approvals/login-guide.blade.php` ／ `resources/views/approvals/admin/organization.blade.php` ／ `resources/views/approvals/admin/users/index.blade.php` ／ `resources/views/auth/change-password.blade.php` |
| テスト | `tests/Concerns/ReadsLoginGuide.php`（新規）／ `tests/Feature/Auth/PasswordChangeTest.php` ／ `tests/Feature/Auth/OtherDeviceLogoutTest.php` ／ `tests/Feature/Approval/{ApprovalOnlyLockoutTest,LoginGuideTest,PasswordReissueTest,ApprovalUserManagementTest,ApprovalUserImportTest,OrganizationManagementTest}.php` ／ `tests/Feature/Admin/UserManagementApprovalTest.php` ／ `tests/Feature/{ClockReadScanTest,StoredTimestampDisplayScanTest}.php`（13.x から来るもの） |
| 記録 | `docs/superpowers/plans/2026-09-24-approval-phase1-fixes.md`（この計画）／ `docs/BACKLOG.md` ／ 段階1 の計画書 ／ `docs/RULES.md`（Bug #63・#64）／ `CLAUDE.md` |

**ルート・DB（段階1 の SQL 以外）・依存の変更は無し。** 新しい PHP クラスは段階1 のものだけ（本番反映で `composer dump-autoload` が要る）。

## 流れ

| Task | 内容 | コミット |
|---|---|---|
| 0 | この計画を docs に保存 | 1 |
| 1 | `approval-followups` を取り込む（記録の衝突を両方残して解く） | マージ 1 |
| 2 | `13.x` を取り込む（BACKLOG の衝突を解く。走査テスト 2 本が赤のまま＝Task 3〜5 で直す） | マージ 1 |
| 3 | F5 発行日を日本の今日に | 1 |
| 4 | F6 最終ログインを日本時間に | 1 |
| 5 | 走査テストの残り 3 か所を分類・通知メールを JapanTime へ | 2 |
| 6 | F1 パスワード変更のあとはその人のホームへ | 1 |
| 7 | F1 と同じ形（キャンセルの戻り先・ホームの別名） | 2 |
| 8 | F2 案内の戻り先を入口ごとに | 1 |
| 9 | F7 通知メールの件数は再発行のときだけ | 1 |
| 10 | F3 略称とアルファベットを 1 行に | 1 |
| 11 | F4 ドメインの削除の確認に人数 | 1 |
| 12 | F8 絞り込みを即時に送る ＋ 独立レビュー | 1〜 |
| 13 | 変異テスト（Bug #44 の作法） | 1（記録） |
| 14 | ローカルの実ブラウザ確認 ＋ コンパイル済みビューの lint | — |
| 15 | 記録（BACKLOG・段階1 計画・RULES・CLAUDE.md） | 1〜2 |
| 16 | 本番反映（**本文で承認を得てから**） | — |
| 17 | 本番の確認と記録・後片付け | 1 |

---

## 共通の決まり

- 作業場所: `/Users/masanori/site/manage/.claude/worktrees/approval-phase1`（vendor・dev 依存あり）。以下 `$WT`
- 使い捨ての置き場: `/private/tmp/claude-501/-Users-masanori-site-manage/25eb9dc7-fb80-4728-8dbc-6c183a02dcd6/scratchpad`。以下 `$S`
  （シェルの変数は呼び出しをまたいで残らないので、コマンドごとに定義する）
- 全件テスト: `cd $WT && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
  （1 本だけ: 末尾に `--filter <テスト名>`）
- `.env` / `.env.*` は読まない。worktree の `.env` は触らず、必要な値は環境変数で上書きする
- `git stash` と `--no-verify` は使わない。コミットのたびに `git status --porcelain` が空であることを確かめる
- コミットの末尾には必ず次のトレーラーを付ける: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- 画面の文言を見るテストでは `assertSessionHas*()` を呼ばない（Bug #49）。期待値は決め打ちで書く（実装と同じ式で組み立てない。Bug #61 ②）

---

## Task 0: 計画を保存する

- [ ] **Step 1: コピーしてコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase1
git status --porcelain   # 空
cp /Users/masanori/.claude/plans/silly-mixing-llama.md docs/superpowers/plans/2026-09-24-approval-phase1-fixes.md
git add docs/superpowers/plans/2026-09-24-approval-phase1-fixes.md
git commit -m "$(cat <<'EOF'
docs: 決裁 段階1 の F1〜F8 を直して本番反映する計画を書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 1: `approval-followups` を取り込む

- [ ] **Step 1: マージする**

```bash
cd $WT && git status --porcelain   # 空
git merge --no-ff approval-followups
```

期待: `docs/BACKLOG.md` と `docs/superpowers/plans/2026-09-16-approval-phase1.md` の 2 つが衝突する（両側が同じ位置に追記している。差分の位置で確認済み）。アプリのコードは衝突しない（`layouts/app.blade.php`・サイドバーの死にファイル 3 本の削除・テスト 2 本は自動）。

- [ ] **Step 2: 衝突を「両方残す」で解く**

- 段階1 の計画書: 「そのほか（レビュー時点）」の箇条の直後に、後続側の 2 行（`→ ①②は 2026-09-18 に approval-followups … ③④は未着手`）を置き、その後ろに phase1 側の追記（Task 15・16 の実測記録）を続ける
- BACKLOG: 「決裁申請 段階1」の節の「検証」の箇条は **phase1 側**の内容（Task 15・16 の結果）を採り、後続側の「### 後続（`approval-followups`。…）」の節を「### ⚠ 本番反映の手順（未実施。設計書 §7）」の**直前**に差し込む（中身はそのまま。状況の更新は Task 15 で行う）

```bash
grep -n -E '^(<<<<<<<|=======|>>>>>>>)' docs/BACKLOG.md docs/superpowers/plans/2026-09-16-approval-phase1.md   # 何も出ないこと
```

- [ ] **Step 3: 全件テスト** — 期待: 全部緑（phase1 の 2068 本に、後続の `LayoutBreadcrumbHomeTest` などが足される。本数を記録する）

- [ ] **Step 4: コミット**

```bash
git add docs/BACKLOG.md docs/superpowers/plans/2026-09-16-approval-phase1.md
git commit -m "$(cat <<'EOF'
Merge branch 'approval-followups' into approval-phase1

パンくずの「ホーム」を利用者ごとの行き先にする修正と、サイドバーの死にファイル 3 本の削除を取り込む。
記録の衝突（BACKLOG・段階1 の計画書）は両方を残して解いた。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `13.x` を取り込む

- [ ] **Step 1: 13.x が進んでいないか見る**

```bash
git -C /Users/masanori/site/manage rev-parse --short 13.x   # 4fa0911e（進んでいたら、その先端をそのまま取り込む）
git diff --name-only $(git merge-base HEAD 13.x) 13.x -- composer.json composer.lock   # 空（vendor を入れ直さなくてよい）
```

- [ ] **Step 2: マージして BACKLOG の衝突を解く**

```bash
git merge --no-ff 13.x
```

期待: 衝突は `docs/BACKLOG.md` の 2 か所だけ。
- 1 か所目（「区画の CSV 取込」の節の後ろ）: 13.x 側の 3 節（日本時間化・月末の溢れ・日付ピッカー）を先に、phase1 側の「✅ 決裁申請 段階1（基幹の改修）— 本番未反映」の節を**最後**に置く（本番反映の順）
- 2 か所目（「バックログ完了状況」）: 13.x 側の段落を先に、phase1 側の「⚠ 決裁申請 段階1 は…本番未反映」の段落を後に置く

⚠ 自動マージの結果を確かめる（13.x 側の日本時間の行が残っていること。phase1 側を丸ごと採ると走査テスト・下限・`UserLastLoginDisplayTest` が落ちる）:

```bash
grep -c "JapanTime::format(\$u->last_login_at, 'm/d H:i')" resources/views/admin/users/index.blade.php   # 1
grep -n -E '^(<<<<<<<|=======|>>>>>>>)' docs/BACKLOG.md CLAUDE.md   # 何も出ないこと
```

- [ ] **Step 3: 全件テスト — 走査テスト 2 本だけが落ちることを確かめる**

期待（違反 5 か所・予測は読み取りで照合済み）:

| テスト | 違反 |
|---|---|
| `ClockReadScanTest::test_php_clock_reads_are_classified` | `app/Support/Approval/LoginGuide.php`（now。F5）／ `app/Support/Approval/PasswordReissuer.php`（now）／ `app/Support/OneTimeAction.php`（now） |
| `StoredTimestampDisplayScanTest::test_stored_timestamps_are_never_formatted_directly` | `resources/views/approvals/admin/users/index.blade.php`（`->last_login_at->format(`。F6）／ `app/Http/Controllers/Admin/UserController.php`（`->deleted_at?->toDateTimeString(`） |

これ以外が落ちたら先へ進まず原因を調べる（superpowers:systematic-debugging）。

- [ ] **Step 4: コミット（走査テストは赤のまま。Task 3〜5 で直す）**

```bash
git add docs/BACKLOG.md
git commit -m "$(cat <<'EOF'
Merge branch '13.x' into approval-phase1

日時の日本時間化（Bug #61）・月末の溢れ（Bug #62）・日付ピッカーを取り込む。
BACKLOG の衝突は両方を残して解いた。13.x の走査テスト 2 本が決裁のコードの 5 か所で落ちるので、
次のコミットで直す（F5・F6 を含む）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: F5 発行日を日本の今日に

**Files:** Modify `app/Support/Approval/LoginGuide.php:44` ／ Test `tests/Feature/Approval/LoginGuideTest.php`

- [ ] **Step 1: 失敗するテストを書く**（`test_the_on_screen_bar_warns_before_closing` の後ろ）

```php
    /**
     * 発行日は**日本の今日**（F5）。アプリの時刻は UTC なので、日本時間の 0:00〜8:59 は UTC ではまだ前日。
     *
     * ⚠ 期待値は決め打ちで書く（JapanTime::today() で組み立てると同義反復になる。Bug #61 ②）。
     */
    public function test_the_issue_date_is_the_date_in_japan(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-18 17:30:00', 'UTC'));   // 日本時間 9/19 2:30

        $html = $this->render()->assertOk()->getContent();

        $this->assertStringContainsString('発行日: 2026年9月19日', $html);
        $this->assertStringNotContainsString('2026年9月18日', $html, 'UTC の日付のまま出ている');
    }
```

- [ ] **Step 2: 落ちることを確かめる** — `--filter test_the_issue_date_is_the_date_in_japan` → FAIL（`発行日: 2026年9月18日` が出ている）

- [ ] **Step 3: 直す**（`use App\Support\JapanTime;` を足し、44 行目を置き換える）

```php
                // 発行日は日本の今日（アプリの時刻は UTC。now() だと日本時間の 0:00〜8:59 に前日が出る。F5）
                'issuedAt'      => JapanTime::today()->format('Y年n月j日'),
```

- [ ] **Step 4: 通ることを確かめる** — 上のテストが PASS、`ClockReadScanTest` の違反から `LoginGuide.php` が消える

- [ ] **Step 5: コミット** — `fix(approval): ログイン案内の発行日を日本の今日にする`（トレーラー付き）

---

## Task 4: F6 最終ログインを日本時間に

**Files:** Modify `resources/views/approvals/admin/users/index.blade.php`（最終ログインのセル）／ Test `tests/Feature/Approval/ApprovalUserManagementTest.php`

- [ ] **Step 1: 失敗するテストを書く**（`test_it_can_filter_people_who_never_logged_in` の後ろ）

```php
    /** 最終ログインは日本時間で出す（F6）。保存は UTC なので、そのまま整形すると 9 時間ずれる */
    public function test_the_last_login_is_shown_in_japan_time(): void
    {
        $member = $this->member(['name' => '決裁 次郎']);
        $member->forceFill(['last_login_at' => \Carbon\CarbonImmutable::parse('2026-09-18 17:30:00', 'UTC')])->save();

        $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))
            ->assertOk()
            ->assertSee('2026/09/19 02:30')
            ->assertDontSee('2026/09/18 17:30');
    }
```

- [ ] **Step 2: FAIL を確かめる**

- [ ] **Step 3: 直す**（最終ログインのセルの中身を 1 行置き換える。書式の既定が同じ `'Y/m/d H:i'` なので見た目は変わらない）

旧:
```blade
{{ $u->last_login_at ? $u->last_login_at->format('Y/m/d H:i') : '未ログイン' }}
```
新:
```blade
{{ \App\Support\JapanTime::format($u->last_login_at) ?? '未ログイン' }}
```

- [ ] **Step 4: PASS と、`StoredTimestampDisplayScanTest` の違反からこのビューが消えることを確かめる**

- [ ] **Step 5: コミット** — `fix(approval): 利用者の管理の最終ログインを日本時間で出す`

---

## Task 5: 走査テストの残り 3 か所を分類・通知メールを JapanTime へ

**Files:** Modify `app/Mail/PasswordReissuedMail.php` ／ `tests/Feature/ClockReadScanTest.php` ／ `tests/Feature/StoredTimestampDisplayScanTest.php`

- [ ] **Step 1: 再発行の通知メールの整形を JapanTime へ寄せる**（`content()` の中。`use App\Support\JapanTime;` を足し、使われなくなった `use Carbon\CarbonImmutable;` を消す）

```php
            // ⚠ 保存・受け渡しは UTC の瞬間。本人が自分のその日と突き合わせられるよう日本時間で出す
            //   （要件 12.5）。日本時間の整形は JapanTime に集約する（Bug #61）
            'reissuedAtText' => JapanTime::format($this->reissuedAt, 'Y年n月j日 H:i'),
```

`--filter PasswordReissueTest` → PASS（`test_the_mail_shows_the_time_in_japan_time` が同じ文字列を見る）。
これで JapanTime の docblock の「部品の外に `Asia/Tokyo` を書いたファイルは 6 本」が取り込み後も正しいまま残る。

コミット: `refactor(approval): 再発行の通知メールの日時を JapanTime で整形する`

- [ ] **Step 2: 理由つきで分類する**

`ClockReadScanTest::ALLOWED` に 2 行:

```php
        'app/Support/Approval/PasswordReissuer.php'           => [1, '再発行の瞬間（通知メールへ渡し、PasswordReissuedMail が日本時間に直して出す）'],
        'app/Support/OneTimeAction.php'                       => [1, '期限: 1 回限りの鍵のキャッシュの有効期限（瞬間でよい）'],
```

`StoredTimestampDisplayScanTest::ALLOWED`（docblock の「今は無い」も直す）:

```php
    private const ALLOWED = [
        'app/Http/Controllers/Admin/UserController.php' => [1, '設定の変更の記録（approval_setting_logs.new_values）に削除の瞬間を UTC のまま残す（記録。画面に出さない。設計書 D14）'],
    ];
```

`MIN_FORMAT_CALLS` を 24 → **26** にし、docblock の実測を「2026-09-24 の実測 = 26（＝下限ちょうど。決裁の利用者の管理の最終ログインと再発行の通知メールの 2 件を足した）」に直す。

- [ ] **Step 3: 全件テスト** → 全部緑（本数を記録）

- [ ] **Step 4: コミット** — `test: 決裁の保存の瞬間・期限・記録を日時の走査に理由つきで分類する`

---

## Task 6: F1 パスワード変更のあとはその人のホームへ

**Files:** Modify `app/Http/Controllers/Auth/PasswordController.php:68` ／ Test `tests/Feature/Auth/PasswordChangeTest.php`・`tests/Feature/Auth/OtherDeviceLogoutTest.php:71`

- [ ] **Step 1: 失敗するテストを書く**（`PasswordChangeTest` に。`use App\Http\Middleware\RestrictApprovalOnlyUsers;` と `use PHPUnit\Framework\Attributes\DataProvider;` を足す）

```php
    /** @return array<string, array{UserRole, string}> */
    public static function homeCases(): array
    {
        return [
            '経営層'   => [UserRole::Executive, 'dashboard.executive'],
            '管理者'   => [UserRole::Manager, 'dashboard.tenant'],
            '一般担当' => [UserRole::Staff, 'dashboard.tenant'],
            '決裁のみ' => [UserRole::ApprovalOnly, 'approvals.home'],
        ];
    }

    /** 変更のあとは、その人のホームへ**直接**戻る（F1。`/dashboard` を経由させない） */
    #[DataProvider('homeCases')]
    public function test_a_successful_change_goes_straight_to_the_users_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role->value, 'must_change_password' => true, 'password' => self::CURRENT]);

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertRedirect(route($home));
    }

    /**
     * 決裁のみ利用者は、決裁のホームで「パスワードを変更しました。」を見て、門番の警告は見ない（F1）。
     *
     * ⚠ 転送をたどって**着いた画面**で見る。行き先の URL だけでは、フラッシュが途中で消えるのを捕まえられない
     *   （旧実装は 302 → 302 → 200 で、着いた先に警告だけが出ていた）。
     */
    public function test_an_approval_only_user_sees_the_success_message_on_the_approval_home(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => true, 'password' => self::CURRENT]);

        $html = $this->followingRedirects()->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html, '決裁のホームに着いていない');
        $this->assertStringContainsString('パスワードを変更しました。', $html, '変更の完了が出ていない（転送の途中で消えた）');
        $this->assertStringNotContainsString(RestrictApprovalOnlyUsers::MESSAGE, $html, '門番に跳ね返されている');
    }

    /** 基幹の人も、着いた画面で「パスワードを変更しました。」を見る（以前は 2 回の転送で消えていた） */
    public function test_a_base_user_sees_the_success_message_on_their_dashboard(): void
    {
        $html = $this->followingRedirects()->submitChangeForm($this->actor(mustChange: true), [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('パスワードを変更しました。', $html);
    }
```

既存の 2 本は行き先の期待を直す: `PasswordChangeTest.php:196` と `OtherDeviceLogoutTest.php:71` の `assertRedirect(route('dashboard'))` → `assertRedirect(route('dashboard.tenant'))`（どちらも一般担当）。

- [ ] **Step 2: FAIL を確かめる**（決裁のみの 2 本・経営層・既存 2 本が落ちる）

- [ ] **Step 3: 直す**（68 行目）

```php
        // ⚠ その人のホームへ直接戻す（`/dashboard` を経由させない）。決裁のみ利用者は門番に跳ね返されて
        //   「決裁以外の画面は使えません。」が出るうえ、2 回目の転送でこのフラッシュが消える
        //   （F1。実測: 302 → 302 → 200）。行き先の規則は User::homeRouteName() の 1 箇所（ログイン直後と同じ）
        return redirect()->route($user->homeRouteName())->with('success', 'パスワードを変更しました。');
```

- [ ] **Step 4: PASS を確かめる**（`PasswordChangeTest`・`OtherDeviceLogoutTest`）

- [ ] **Step 5: コミット** — `fix(auth): パスワードを変えたあとはその人のホームへ直接戻す`

---

## Task 7: F1 と同じ形（キャンセルの戻り先・ホームの別名）

### 7a キャンセルの戻り先

**Files:** Modify `resources/views/auth/change-password.blade.php:128` ／ Test `tests/Feature/Auth/PasswordChangeTest.php`

- [ ] **Step 1: 失敗するテスト**

```php
    /**
     * キャンセルの戻り先は、直前の画面が無いとき（＝検証エラーで差し戻された直後）もその人のホーム（F1 と同じ形）。
     *
     * ⚠ 旧実装は `route('dashboard')` へ戻し、決裁のみ利用者は門番に跳ね返されて警告を見た。
     * ⚠ リンクを要素ごと切り出して見る。`approvals.home` はパンくずとサイドバーにも出るので、ページ全体では false-pass する。
     */
    #[DataProvider('homeCases')]
    public function test_the_cancel_link_falls_back_to_the_users_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role->value, 'must_change_password' => false]);

        // 差し戻された直後と同じ（直前の画面＝この画面自身）
        $html = $this->actingAs($user)->from(route('password.change'))->get(route('password.change'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a\s+href="([^"]*)"[^>]*>\s*キャンセル\s*<\/a>/u', $html, $m), 'キャンセルのリンクが見つからない');
        $this->assertSame(route($home), html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
```

- [ ] **Step 2: FAIL を確かめる** → **Step 3: 直す**

```blade
                            {{-- ⚠ 直前の画面が無いときの戻り先は `route('dashboard')` にしない（決裁のみ利用者は門番に
                                 跳ね返されて警告を見る。F1 と同じ形）。行き先の規則は User::homeRouteName() --}}
                            <a
                                href="{{ url()->previous() !== url()->current() ? url()->previous() : route(auth()->user()->homeRouteName()) }}"
```

- [ ] **Step 4: PASS** → **Step 5: コミット** — `fix(auth): パスワード変更のキャンセルの戻り先をその人のホームにする`

### 7b 門番の「ホームの別名」

**Files:** Modify `app/Http/Middleware/RestrictApprovalOnlyUsers.php` ／ Test `tests/Feature/Approval/ApprovalOnlyLockoutTest.php`（`use PHPUnit\Framework\Attributes\DataProvider;` を足す）

- [ ] **Step 1: 失敗するテスト**

```php
    /** @return array<string, array{string}> */
    public static function homeAliasCases(): array
    {
        return [
            'サイトの入口 /'           => ['/'],
            'ダッシュボード /dashboard' => ['/dashboard'],
            'ログイン中のログイン画面'   => ['/login'],
        ];
    }

    /**
     * 「ホーム」の意味の入口は、警告なしで決裁のホームへ（F1 と同じ形）。
     *
     * ⚠ 案内の QR はログイン画面の URL。ログインしたまま QR やブックマークから開くと
     *   `/login` → `/dashboard` → 門番、と転送される。旧実装はそのたびに警告を出していた。
     * ⚠ 本物の基幹の画面では今までどおり警告が出る（`test_the_reason_is_shown_after_the_redirect` が固定する）。
     */
    #[DataProvider('homeAliasCases')]
    public function test_home_aliases_land_on_the_approval_home_without_the_warning(string $uri): void
    {
        $html = $this->actingAs($this->approvalOnlyUser())
            ->followingRedirects()->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html, '決裁のホームに着いていない');
        $this->assertStringNotContainsString(RestrictApprovalOnlyUsers::MESSAGE, $html, 'ホームを開いただけなのに警告が出ている');
    }
```

- [ ] **Step 2: FAIL を確かめる**（3 件とも警告が出る）

- [ ] **Step 3: 直す** — `handle()` の 403 の判定の**後**・警告つきの転送の**前**に:

```php
        // 「ホーム」を意味するだけの入口は、警告を付けずに決裁のホームへ送る（F1 と同じ形）。
        // 決裁のみ利用者にとってのホームは決裁のホームなので、「使えない画面を開いた」わけではない。
        // ⚠ 通す（ALLOWED_NAMES に足す）のではない。行き先は今までどおり門番が決める
        if ($this->isHomeAlias($request)) {
            return redirect()->route('approvals.home');
        }
```

```php
    /** `/`（名前の無いルート）と `/dashboard`。ログイン中の `/login` は RedirectIfAuthenticated が `/dashboard` へ送る */
    private function isHomeAlias(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && ($route->getName() === 'dashboard' || $route->uri() === '/');
    }
```

クラスの docblock（「通すのは 3 種類だけ」の段）に「ホームの別名（`/`・`/dashboard`）は通さずに、警告なしで決裁のホームへ送る」を 1 行足す。

- [ ] **Step 4: 全件テスト**（`ApprovalOnlyLockoutTest` の全件分類・`LoginIdentifierTest:264` も緑のまま）

- [ ] **Step 5: コミット** — `fix(approval): ホームの意味の入口では決裁のみ利用者に警告を出さない`

---

## Task 8: F2 案内の戻り先を入口ごとに

**Files:** Modify `app/Support/Approval/LoginGuide.php` ／ `app/Support/Approval/ReissueResult.php` ／ `resources/views/approvals/login-guide.blade.php:65` ／ 入口 5 つ（`Admin/UserController.php:165,362`・`Approval/UserController.php:275,323`・`Approval/UserImportController.php:141`）／ Create `tests/Concerns/ReadsLoginGuide.php` ／ Tests: `LoginGuideTest`（`guide()`）・`PasswordReissueTest:193`・`UserManagementApprovalTest`・`ApprovalUserManagementTest`・`ApprovalUserImportTest`

- [ ] **Step 1: 往復の補助を作る**（`tests/Concerns/ReadsLoginGuide.php`）

```php
<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * ログイン案内の「元の画面へ戻る」を、ブラウザと同じようにたどる（F2・Bug #47）。
 *
 * ⚠ テストの HTTP クライアントは**リファラーを送らない**（`from()` を使ったときだけ送る）。
 *   `url()->previous()` はリファラーを優先するので、リファラー無しのテストでは F2（CSV の確定で 405）が
 *   原理的に見えなかった。案内を出す POST は必ず `from(フォームが載っていた画面の URL)` で送ること。
 */
trait ReadsLoginGuide
{
    protected function guideBackHref(string $html): string
    {
        $this->assertSame(
            1,
            preg_match_all('/<a\b[^>]*\bclass="guide-back"[^>]*>元の画面へ戻る<\/a>/u', $html, $links),
            '「元の画面へ戻る」がちょうど 1 つでない'
        );
        $this->assertSame(1, preg_match('/\bhref="([^"]*)"/', $links[0][0], $href), '「元の画面へ戻る」に href が無い');

        return html_entity_decode($href[1], ENT_QUOTES, 'UTF-8');
    }

    /** 行き先が $expected で、その画面が実際に開く（405 などでない）こと */
    protected function assertGuideGoesBackTo(TestResponse $guide, string $expected, User $as): void
    {
        $href = $this->guideBackHref($guide->getContent());

        $this->assertSame($expected, $href, '「元の画面へ戻る」の行き先が違う');
        $this->actingAs($as)->get($href)->assertOk();
    }
}
```

- [ ] **Step 2: テストを書く**（各クラスに `use Tests\Concerns\ReadsLoginGuide;`）

`ApprovalUserImportTest` — `confirm()` の確定の POST を `->from(route('approvals.admin.users.import.preview'))` 付きにし（コメント:「確定のフォームは確認画面＝ preview の POST の応答に載っている。ブラウザのリファラーは POST 専用のこの URL」）、1 本足す:

```php
    /** 案内の「元の画面へ戻る」は取込の画面へ（F2。旧実装はリファラー＝ POST 専用の preview へ戻し、押すと 405） */
    public function test_the_guide_after_an_import_goes_back_to_the_import_screen(): void
    {
        $guide = $this->confirm("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertGuideGoesBackTo($guide, route('approvals.admin.users.import'), $this->admin());
    }
```

`ApprovalUserManagementTest` — 2 本（1 人の再発行・絞り込んだ全員）:

```php
    /** 案内の「元の画面へ戻る」は、再発行したときの一覧へ絞り込みを保ったまま戻る（F2） */
    public function test_the_guide_after_a_reissue_goes_back_to_the_filtered_list(): void
    {
        $admin  = $this->admin();
        $member = $this->member(['name' => '決裁 次郎']);
        $list   = route('approvals.admin.users.index', ['kind' => 'approval', 'search' => '次郎']);

        $html = $this->actingAs($admin)->get($list)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.users.reissue', $member) . '"');

        $guide = $this->actingAs($admin)->from($list)->post($form['action'], $form['fields'])->assertOk();

        $this->assertGuideGoesBackTo($guide, $list, $admin);
    }

    public function test_the_guide_after_a_bulk_reissue_goes_back_to_the_filtered_list(): void
    {
        $admin = $this->admin();
        $this->member(['name' => '田中 一郎']);
        $list  = route('approvals.admin.users.index', ['search' => '田中']);

        $html = $this->actingAs($admin)->get($list)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.users.reissueBulk') . '"');

        $guide = $this->actingAs($admin)->from($list)->post($form['action'], $form['fields'] + ['mode' => 'filtered'])->assertOk();

        $this->assertGuideGoesBackTo($guide, $list, $admin);
    }
```

`UserManagementApprovalTest` — 2 本（新規登録・再発行。一覧の URL に絞り込みを付けて `from()` で送る。新規登録は `createForm()` の値を `test_creating_a_user_renders_the_login_guide` と同じにする）:

```php
    public function test_the_guide_after_creating_goes_back_to_the_list(): void
    {
        $executive = $this->executive();
        $list      = route('admin.users.index', ['search' => '甲']);
        [$form, $fields] = $this->createForm([
            'name' => '甲 一郎', 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $guide = $this->actingAs($executive)->from($list)->post($form['action'], $fields)->assertOk();

        $this->assertGuideGoesBackTo($guide, $list, $executive);
    }

    public function test_the_guide_after_a_reissue_goes_back_to_the_filtered_list(): void
    {
        $executive = $this->executive();
        $target    = User::factory()->create(['name' => '甲 一郎', 'must_change_password' => false]);
        $list      = route('admin.users.index', ['search' => '甲']);

        $html = $this->actingAs($executive)->get($list)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.resetPassword', $target) . '"');

        $guide = $this->actingAs($executive)->from($list)->post($form['action'], $form['fields'])->assertOk();

        $this->assertGuideGoesBackTo($guide, $list, $executive);
    }
```

- [ ] **Step 3: FAIL を確かめる** — CSV の 1 本だけが落ちる（行き先が preview の URL）。一覧へ戻る 4 本は今の実装でも緑（**振る舞いを固定するため**。Task 13 で「固定の一覧へ戻す」変異を当てて守りを測る）

- [ ] **Step 4: 直す**

`LoginGuide`（`$backUrl` を**必須**に。入口ごとに決めさせる）:

```php
    /**
     * @param  list<array{user: User, password: string}>  $entries
     * @param  string  $backUrl  「元の画面へ戻る」の行き先（F2）。**入口ごとに決める**:
     *   フォームが GET の画面に載っている入口はリファラー（`url()->previous()`。絞り込み・ページ番号が残る）、
     *   確認画面（POST の応答）から送る CSV の確定は取込の画面。
     *   ⚠ ビューで `url()->previous()` を呼ばない。リファラーが優先されるので、CSV の確定では
     *   POST 専用の URL になり、押すと 405 だった（docs/RULES.md Bug #64）
     * @param  int  $notifiedCount  通知メールを送る人数
     * @param  int  $skippedCount   送らない人数（メールアドレスなし・許可していないドメイン）
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $backUrl,
        private readonly int $notifiedCount = 0,
        private readonly int $skippedCount = 0,
    ) {}
```

`toResponse()` のビューのデータに `'backUrl' => $this->backUrl,` を足し、ビュー 65 行目を `<a href="{{ $backUrl }}" class="guide-back">元の画面へ戻る</a>` に。

`ReissueResult::toGuide(string $backUrl): LoginGuide` → `new LoginGuide($this->entries, $backUrl, $this->notifiedCount, $this->skippedCount)`。

入口:
- `Admin/UserController::store` — `new LoginGuide([['user' => $user, 'password' => $password]], backUrl: url()->previous())`
  （コメント: フォームは一覧＝ GET の画面に載っているので、リファラー＝絞り込み込みの一覧へ戻る）
- `Admin/UserController::resetPassword`・`Approval/UserController::reissue`・`reissueBulk` — `->toGuide(url()->previous())`
- `Approval/UserImportController::execute` — `new LoginGuide($entries, backUrl: route('approvals.admin.users.import'), notifiedCount: 0, skippedCount: 0)`
  （コメント: 確定のフォームは確認画面＝ preview の POST の応答に載っている。リファラーは POST 専用の URL なので、元の画面＝取込の画面を明示する）

テストの呼び出しも合わせる: `LoginGuideTest::guide()` に `backUrl: 'http://localhost/_test/back',` ／ `PasswordReissueTest.php:193` を `$result->toGuide('http://localhost/_test/back')`。

⚠ `LoginGuideTest` の入口の走査（入口ちょうど 5・`claimFrom()` の防御が先）は変わらないこと（`url()->previous()` は防御の後ろ）。

- [ ] **Step 5: 全件テスト** → 緑 → **Step 6: コミット** — `fix(approval): ログイン案内の「元の画面へ戻る」を入口ごとに決める（CSV の確定で 405 だった）`

---

## Task 9: F7 通知メールの件数は再発行のときだけ

**Files:** Modify `LoginGuide.php` ／ `ReissueResult.php` ／ `login-guide.blade.php:64` ／ `UserImportController.php:141` ／ Tests: `LoginGuideTest`・`UserManagementApprovalTest`・`ApprovalUserImportTest`・`ApprovalUserManagementTest`

- [ ] **Step 1: テストを書く**

`LoginGuideTest`:

```php
    /** 通知メールの件数は、渡されたとき（＝再発行）だけ出す（F7。新規登録と CSV の確定では送らない） */
    public function test_the_mail_counts_are_left_out_when_no_mail_is_sent(): void
    {
        $user = User::factory()->create(['name' => '丙 三郎', 'employee_number' => 'M003', 'must_change_password' => true]);
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->get('/_test/login-guide-new', fn () =>
            (new LoginGuide([['user' => $user, 'password' => 'klmno45678']], backUrl: url('/_test/back')))->toResponse(request()));

        $html = $this->actingAs(User::factory()->create(['must_change_password' => false]))
            ->get('/_test/login-guide-new')->assertOk()->getContent();

        $this->assertStringContainsString('1 人分', $html);
        $this->assertStringNotContainsString('通知メール: 送る', $html, '通知メールを送らないのに件数の帯が出ている');
    }
```

入口の画面で役割ごとに（F7 の対）:
- `UserManagementApprovalTest::test_creating_a_user_renders_the_login_guide` に `$this->assertStringNotContainsString('通知メール: 送る', $html, '新規登録なのに通知メールの件数が出ている（F7）');`
- `ApprovalUserImportTest::test_a_new_user_is_created_as_an_approval_only_user` に同じ 1 行
- `ApprovalUserManagementTest::test_reissue_renders_the_guide` に `$this->assertStringContainsString('通知メール: 送る 0 人／送らない 1 人', $html);`（決裁のみの人はメールアドレスが無い＝ factory の `approvalOnly()`）
- `ApprovalUserManagementTest::test_the_filtered_bulk_reissue_submits_the_form_the_page_rendered` に同じ 1 行（`$guide`）
- 基幹の再発行は既存の `UserManagementApprovalTest.php:557` が見ている

- [ ] **Step 2: FAIL を確かめる**（`LoginGuideTest` の新しい 1 本・新規登録・CSV の 3 本）

- [ ] **Step 3: 直す** — `LoginGuide` の 2 つの int を 1 つの任意の配列にする（片方だけ渡す誤りを型で防ぐ）:

```php
     * @param  array{notified: int, skipped: int}|null  $mailCounts  通知メールの人数。**再発行のときだけ**渡す
     *   （F7。新規登録と CSV の確定では通知メールを送らないので、帯にも出さない。設計書 §5.10・§5.13）
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $backUrl,
        private readonly ?array $mailCounts = null,
    ) {}
```

ビューのデータを `'mailCounts' => $this->mailCounts,` にし、帯を:

```blade
    @if($mailCounts !== null)
        {{-- 通知メールを送るのは再発行のときだけ（F7） --}}
        <span style="margin-left: 12px;">通知メール: 送る {{ $mailCounts['notified'] }} 人／送らない {{ $mailCounts['skipped'] }} 人（メールアドレスなし・許可していないドメイン）</span>
    @endif
```

`ReissueResult::toGuide()` → `new LoginGuide($this->entries, $backUrl, ['notified' => $this->notifiedCount, 'skipped' => $this->skippedCount])`。
`UserImportController::execute` → `new LoginGuide($entries, backUrl: route('approvals.admin.users.import'))`（件数を渡さない。コメント「新規登録では通知メールを送らない」はそのまま）。
`LoginGuideTest::guide()` → `mailCounts: ['notified' => 2, 'skipped' => 1]`。

- [ ] **Step 4: 全件テスト** → 緑 → **Step 5: コミット** — `fix(approval): 通知メールの件数は再発行の案内にだけ出す`

---

## Task 10: F3 略称とアルファベットを 1 行に

**Files:** Modify `resources/views/approvals/admin/organization.blade.php:97-98` ／ Test `OrganizationManagementTest`

- [ ] **Step 1: 失敗するテスト**

```php
    /**
     * 略称とアルファベットは 1 行で出す（F3）。見出しは `w-[1%] whitespace-nowrap` なので、
     * 中身が折り返せると列が見出しの幅まで縮み、1 文字ずつ縦に並ぶ（1440px でも「不／動／産」）。
     */
    public function test_the_short_name_and_code_cells_do_not_wrap(): void
    {
        $this->department($this->company(), ['short_name' => '不動産', 'code' => 'RE']);

        $html = $this->indexHtml($this->approvalAdmin());

        foreach (['不動産', 'RE'] as $value) {
            $this->assertMatchesRegularExpression(
                '/<td class="[^"]*\bwhitespace-nowrap\b[^"]*">' . preg_quote($value, '/') . '<\/td>/u',
                $html,
                "「{$value}」のセルが折り返せる（whitespace-nowrap が無い）"
            );
        }
    }
```

- [ ] **Step 2: FAIL** → **Step 3: 直す**（97・98 行の `<td>` の class に `whitespace-nowrap` を足す）→ **Step 4: PASS** → **Step 5: コミット** — `fix(approval): 部門の管理の略称とアルファベットを折り返さない`

---

## Task 11: F4 ドメインの削除の確認に人数

**Files:** Modify `resources/views/approvals/admin/organization.blade.php:134-137` ／ Test `OrganizationManagementTest`（`use Illuminate\Support\Js;` を足す）

- [ ] **Step 1: 既存の `test_deleting_a_domain_shows_how_many_people_lose_notifications` を置き換える**

```php
    /**
     * 削除の確認に、通知メールが届かなくなる人数を出す（設計書 §5.8・F4）。行には中立な人数だけ。
     *
     * ⚠ 「届かなくなります」を行に常に出すと「今届いていない」と読める（旧実装）。
     * ⚠ 確認の文言は `Js::from()` が日本語を `\u` でエスケープするので、行の文言とは混ざらない。
     *   期待値は決め打ちの文字列に同じ変換をかけて突き合わせる。
     * ⚠ 人数の違うドメインを 3 つ並べる（別の行の人数を使う・0 人でも注意を出す、の取り違えを見分けるため）。
     */
    public function test_the_delete_confirmation_warns_how_many_people_lose_notifications(): void
    {
        $two  = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        $one  = ApprovalMailDomain::create(['domain' => 'dad-mitsuwa.jp']);
        $none = ApprovalMailDomain::create(['domain' => 'zeal-mitsuwa.jp']);
        User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'c@dad-mitsuwa.jp', 'must_change_password' => false]);

        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('このドメインのメールアドレスを持つ利用者: 2 人', $html);
        $this->assertStringNotContainsString('届かなくなります', $html, '行に「届かなくなります」が常に出ている');

        $expected = [
            [$two, "このドメインを削除しますか。\n\nこのドメインのメールアドレスを持つ利用者: 2 人（この人たちには通知メールが届かなくなります）"],
            [$one, "このドメインを削除しますか。\n\nこのドメインのメールアドレスを持つ利用者: 1 人（この人たちには通知メールが届かなくなります）"],
            [$none, 'このドメインを削除しますか。'],
        ];

        foreach ($expected as [$domain, $message]) {
            $action = route('approvals.admin.organization.mailDomains.destroy', $domain);
            $pos    = strpos($html, 'action="' . $action . '"');
            $this->assertNotFalse($pos, "{$domain->domain} の削除フォームが無い");
            $open    = strrpos(substr($html, 0, $pos), '<form');
            $openTag = substr($html, $open, strpos($html, '>', $pos) - $open + 1);

            $this->assertSame('return confirm(' . Js::from($message) . ');', $this->htmlAttr($openTag, 'onsubmit'), "{$domain->domain} の削除の確認が違う");
        }
    }
```

- [ ] **Step 2: FAIL** → **Step 3: 直す**（行の `<li>` の中を）

```blade
                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-gray-100 text-[13px]">
                    @php
                        // 削除の確認に、通知メールが届かなくなる人数を出す（設計書 §5.8・F4）。
                        // ⚠ 行には中立な人数だけ。「届かなくなります」を行に常に出すと「今届いていない」と読める
                        $deleteConfirm = 'このドメインを削除しますか。';
                        if ($domain->affected_user_count > 0) {
                            $deleteConfirm .= "\n\nこのドメインのメールアドレスを持つ利用者: {$domain->affected_user_count} 人（この人たちには通知メールが届かなくなります）";
                        }
                    @endphp
                    <span class="font-mono text-gray-900">{{ $domain->domain }}</span>
                    <span class="text-[12px] text-gray-500">このドメインのメールアドレスを持つ利用者: {{ $domain->affected_user_count }} 人</span>
                    <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.destroy', $domain) }}" class="ml-auto" onsubmit="return confirm({{ \Illuminate\Support\Js::from($deleteConfirm) }});">
```

（会社・部門の削除と同じく素の `confirm()` のまま。同じ画面の決裁の利用者の管理にも `Js::from` を `confirm()` に入れる前例がある）

- [ ] **Step 4: PASS**（`test_a_domain_can_be_deleted` の往復も緑のまま）→ **Step 5: コミット** — `fix(approval): ドメインの削除の確認に通知メールが届かなくなる人数を出す`

---

## Task 12: F8 絞り込みを即時に送る ＋ 独立レビュー

**Files:** Modify `resources/views/approvals/admin/users/index.blade.php:47-72` ／ Test `ApprovalUserManagementTest`

- [ ] **Step 1: 失敗するテスト**

```php
    /**
     * 絞り込みのプルダウンとチェックは、変えた瞬間に送る（F8・CLAUDE.md の即時フィルタ）。
     *
     * ⚠ まとめて再発行の hidden は**適用済みの**条件を運ぶ。「検索」を押さずに変えたままだと、
     *   画面の表示と再発行の対象が食い違っていた。
     * ⚠ 全件分類: フォームの中の select と checkbox を機械的に拾い、1 つでも即時に送らなければ落とす
     *   （あとで足した絞り込みが無検査にならないように。Top trap #13）。
     */
    public function test_every_filter_control_submits_on_change(): void
    {
        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form\b[^>]*\bid="filter-form"[^>]*>.*?<\/form>/s', $html, $form), '絞り込みのフォーム（id="filter-form"）が無い');
        preg_match_all('/<select\b[^>]*>|<input\b[^>]*\btype="checkbox"[^>]*>/i', $form[0], $controls);

        $this->assertGreaterThanOrEqual(4, count($controls[0]), '絞り込みの select / checkbox を拾えていない（走査の空振り）');
        foreach ($controls[0] as $tag) {
            $this->assertSame("document.getElementById('filter-form').submit()", $this->htmlAttr($tag, 'onchange'), "即時に送らない絞り込みがある: {$tag}");
        }

        // 検索語は打鍵ごとに送らない（「検索」ボタン／Enter で送る）
        $this->assertSame(1, preg_match('/<input\b[^>]*\bname="search"[^>]*>/i', $form[0], $search));
        $this->assertNull($this->htmlAttr($search[0], 'onchange'));
        $this->assertStringContainsString('>検索</button>', $form[0]);
    }
```

- [ ] **Step 2: FAIL** → **Step 3: 直す**

- フォームの開始タグに `id="filter-form"`
- `<select name="kind"`・`<select name="department"`・`<select name="status"`・`<input type="checkbox" name="never_logged_in"` の各タグに `onchange="document.getElementById('filter-form').submit()"`
- フォームの直前に Blade コメント:

```blade
    {{-- 絞り込みのプルダウンとチェックは、変えた瞬間に送る（F8・CLAUDE.md の即時フィルタ）。
         まとめて再発行の hidden は適用済みの条件を運ぶので、画面の表示と再発行の対象を一致させる。
         検索語は「検索」ボタン／Enter で送る（打鍵ごとに送らない） --}}
```

- [ ] **Step 4: 全件テスト**（`test_the_page_never_nests_forms` も緑）→ **Step 5: コミット** — `fix(approval): 利用者の管理の絞り込みを変えた瞬間に送る`

- [ ] **Step 6: 独立レビュー** — superpowers:requesting-code-review で、Task 3〜12 の差分（`git diff <Task 2 のマージ>..HEAD`）を別のエージェントに見てもらう。観点: F1〜F8 の直し方が「決めたこと」どおりか ／ 同じ形の取りこぼし（Bug #41・#43）／ テストが「緑でも守っていない」形になっていないか（部分一致・同じ文字列が 2 か所・リファラーの有無）。指摘は実測で裏を取ってから直す（superpowers:receiving-code-review）

---

## Task 13: 変異テスト（Bug #44 の作法）

作法: ①**先にコミット** ②各変異の前に `git status --porcelain` が空 ③当てた直後に `git diff --stat` が非空（着弾）④全件を `--log-junit` で流す ⑤**落ちたテストの集合と理由の 1 行目**まで記録 ⑥`git checkout -- <当該ファイル>` で戻す ⑦空を再確認。**置換は出現がちょうど 1 回のときだけ当てる**（Bug #44 の「狙う行を明示する」）。

実行役（scratchpad の `mutate.py`。戻しは `finally` で必ず行い、出力は `errors='replace'` で復号する＝ Bug #59 の教訓）。変異は下の表から `mutants.json`（`[{"id", "file", "old", "new"}]`。`old` はコミット済みのコードから**そのまま**写す）に書く:

```python
#!/usr/bin/env python3
# 変異を 1 つずつ当てて全件を流し、落ちたテストと理由を記録する（docs/RULES.md Bug #44 の作法）
import base64, json, os, subprocess, sys
import xml.etree.ElementTree as ET

WT = '/Users/masanori/site/manage/.claude/worktrees/approval-phase1'
OUT = os.path.dirname(os.path.abspath(sys.argv[1]))
MUTANTS = json.load(open(sys.argv[1], encoding='utf-8'))


def run(*args, env=None):
    return subprocess.run(args, cwd=WT, capture_output=True, env=env)


def clean():
    return run('git', 'status', '--porcelain').stdout.decode('utf-8', 'replace').strip() == ''


results = []
for m in MUTANTS:
    assert clean(), m['id'] + ' の前に作業ツリーが空でない'
    path = os.path.join(WT, m['file'])
    src = open(path, encoding='utf-8').read()
    if src.count(m['old']) != 1:
        results.append({'id': m['id'], 'status': 'NOT-APPLIED', 'count': src.count(m['old'])})
        continue
    try:
        open(path, 'w', encoding='utf-8').write(src.replace(m['old'], m['new']))
        assert run('git', 'diff', '--stat').stdout.strip(), m['id'] + ' が着弾していない'
        junit = os.path.join(OUT, m['id'] + '.xml')
        env = dict(os.environ, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode())
        run('./vendor/bin/phpunit', '--log-junit', junit, env=env)
        failed = []
        for case in ET.parse(junit).iter('testcase'):
            for f in case.findall('failure') + case.findall('error'):
                first = ((f.get('message') or f.text or '').strip().splitlines() or [''])[0]
                failed.append(case.get('class', '') + '::' + case.get('name', '') + ' — ' + first[:160])
        results.append({'id': m['id'], 'status': 'RED' if failed else 'GREEN', 'failed': failed})
    finally:
        run('git', 'checkout', '--', m['file'])
    assert clean(), m['id'] + ' の後に作業ツリーが空に戻らない'

json.dump(results, open(os.path.join(OUT, 'results.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
for r in results:
    print(r['id'], r['status'], *(r.get('failed') or [])[:8], sep='\n  ')
```

実行: `python3 "$S/mutate.py" "$S/mutants.json"`（`$S` は scratchpad）。

| # | 変異 | 期待（落ちるテスト） |
|---|---|---|
| M00 | カナリア: `login-guide.blade.php` に未定義の変数を出す | 案内を描く全テスト |
| M01 | F1: `PasswordController` を `route('dashboard')` に戻す | 決裁のみの 2 本・経営層（DataProvider）・既存の行き先 2 本 |
| M02 | F1: 行き先を `approvals.home` に固定 | 基幹 3 ロール・基幹の完了表示 |
| M03 | 7a: キャンセルの戻り先を `route('dashboard')` に戻す | キャンセルの 4 ケース |
| M04 | 7b: `isHomeAlias` の分岐を消す | ホームの別名 3 ケース |
| M05 | 7b: 判定を `str_starts_with($name, 'dashboard')` に広げる | `test_the_reason_is_shown_after_the_redirect`（`/dashboard/tenant` の警告が消える） |
| M06 | F2: ビューを `url()->previous()` に戻す | CSV の往復（405 / 行き先違い） |
| M07 | F2: CSV の入口を `url()->previous()` に | CSV の往復 |
| M08 | F2: 基幹の再発行を `route('admin.users.index')` 固定に | 基幹の再発行の往復（絞り込みが消える） |
| M09 | F2: まとめて再発行を `route('approvals.admin.users.index')` 固定に | まとめて再発行の往復 |
| M10 | F5: `JapanTime::today()` を `now()` に戻す | 発行日のテスト ＋ `ClockReadScanTest` |
| M11 | F6: `JapanTime::format` を直接の `->format()` に戻す | 最終ログインのテスト ＋ `StoredTimestampDisplayScanTest` |
| M12 | F7: 帯の `@if` を外す | 件数なしの 3 本 |
| M13 | F7: `ReissueResult` が件数を渡さない | 再発行の帯の 4 本 |
| M14 | F3: 略称のセルから `whitespace-nowrap` を外す | F3 のテスト |
| M15 | F3: アルファベットのセルから外す | F3 のテスト |
| M16 | F4: 行に「（この人たちには…届かなくなります）」を戻す | F4 のテスト（行） |
| M17 | F4: 確認を「このドメインを削除しますか。」だけに戻す | F4 のテスト（2 人・1 人） |
| M18 | F4: `> 0` の条件を外す（0 人でも注意） | F4 のテスト（0 人） |
| M19 | F8: `department` の `onchange` を消す | F8 のテスト |
| M20 | F8: チェックの `onchange` を消す | F8 のテスト |
| M21 | F8: フォームの `id` を消す | F8 のテスト |
| M22 | 取り込み: `ClockReadScanTest::ALLOWED` から `OneTimeAction` を消す | `ClockReadScanTest` |
| M23 | 取り込み: 再発行メールを `setTimezone('UTC')` 相当に（`JapanTime::format` → `$this->reissuedAt->format(...)`） | `PasswordReissueTest::test_the_mail_shows_the_time_in_japan_time` |

期待と違った変異は、テストを足して赤になるまで測り直す（その経緯も記録）。全表を `docs/superpowers/plans/2026-09-24-approval-phase1-fixes.md` の末尾「実測記録」に書いてコミットする。

---

## Task 14: ローカルの実ブラウザ確認 ＋ コンパイル済みビューの lint

⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない（安全の決まり）。ログインは**使い捨てのログイン用ルート**（コミットしない。確認後に必ず戻す）。
　 F1 のパスワード変更の画面操作はテスト（実際のミドルウェアを通る転送の追跡）で代える。画面で見たい場合は利用者の手で。

- [ ] **Step 1: 使い捨ての環境**

```bash
cd $WT
S=/private/tmp/claude-501/-Users-masanori-site-manage/25eb9dc7-fb80-4728-8dbc-6c183a02dcd6/scratchpad
DB="$S/approval-fixes-$$.sqlite" && : > "$DB"   # ⚠ macOS の mktemp は末尾以外の X を置き換えない
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DB" QUEUE_CONNECTION=sync MAIL_MAILER=log
php artisan migrate --force
php "$S/seed-approval-fixes.php"       # 下の中身
/Users/masanori/site/manage/node_modules/.bin/vite build   # cwd=worktree（public/build は gitignore 済み）
```

`$S/seed-approval-fixes.php`（scratchpad。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき）:

```php
<?php
// 使い捨ての SQLite に F1〜F8 の確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\Department;
use App\Models\User;

$root = '/Users/masanori/site/manage/.claude/worktrees/approval-phase1';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = (string) config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || ! str_starts_with($path, '/private/tmp/claude-501/')) {
    fwrite(STDERR, '中断: 接続先が使い捨ての SQLite ではない（' . config('database.default') . " {$path}）\n");
    exit(1);
}

$base = Department::create(['name' => 'テナント', 'code' => 'tenant', 'display_order' => 1]);
$co   = ApprovalCompany::create(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1]);
$re   = ApprovalDepartment::create(['company_id' => $co->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
$sa   = ApprovalDepartment::create(['company_id' => $co->id, 'name' => '営業部', 'short_name' => '営業', 'code' => 'SA', 'sort_order' => 2]);
foreach (['mitsuwat.co.jp', 'dad-mitsuwa.jp', 'zeal-mitsuwa.jp'] as $domain) {
    ApprovalMailDomain::create(['domain' => $domain]);
}

$make = function (string $number, string $name, UserRole $role, ?string $email, array $extra = []): User {
    $user = new User();
    $user->forceFill(array_merge([
        'name' => $name, 'employee_number' => $number, 'email' => $email,
        'role' => $role->value, 'status' => UserStatus::Active->value,
        // ブラウザでは使わない（使い捨てのログイン用ルートで入る）
        'password' => bin2hex(random_bytes(16)), 'must_change_password' => false,
    ], $extra))->save();

    return $user;
};

$e1 = $make('E0001', '経営 一郎', UserRole::Executive, 'e0001@mitsuwat.co.jp');
$e1->departments()->attach($base->id);
ApprovalMember::create(['user_id' => $e1->id, 'is_admin' => true]);

$make('S0001', '担当 二郎', UserRole::Staff, 's0001@mitsuwat.co.jp')->departments()->attach($base->id);

$a2 = $make('A0002', '決裁 管理', UserRole::ApprovalOnly, null);
$a2->approvalDepartments()->attach($re->id);
ApprovalMember::create(['user_id' => $a2->id, 'is_admin' => true]);

$make('A0005', '決裁 五郎', UserRole::ApprovalOnly, 'a0005@dad-mitsuwa.jp', ['last_login_at' => '2026-09-18 17:30:00'])
    ->approvalDepartments()->attach($re->id);
$make('A0006', '決裁 六郎', UserRole::ApprovalOnly, null)->approvalDepartments()->attach($sa->id);

foreach (User::orderBy('id')->get() as $u) {
    echo "{$u->id}\t{$u->employee_number}\t{$u->role->value}\t{$u->name}\n";   // ログイン用ルートの id に使う
}
```

ドメインの人数: `mitsuwat.co.jp` = 2（E0001・S0001）／ `dad-mitsuwa.jp` = 1（A0005）／ `zeal-mitsuwa.jp` = 0。
取込の CSV（項目 5。Playwright MCP が読めるのは repo の下だけなので `.playwright-mcp/` に置く。BOM つき UTF-8）:
`社員番号,氏名,メールアドレス,所属部門` ／ `A0101,取込 一郎,,RE` ／ `A0102,取込 二郎,,SA`。

使い捨てのログイン用ルート（`routes/web.php` の末尾に一時的に足す。**コミットしない**）:

```php
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect('/');
});
```

`php artisan serve --host=127.0.0.1 --port=8765` を Bash の `run_in_background` で起動。

- [ ] **Step 2: 見ること**（Playwright・画面が見えている状態。コンソールのエラーも毎画面見る）

| # | 画面 | 見ること |
|---|---|---|
| 1 | 決裁のみ（A0005）でログイン → `/` | 決裁のホームに着き、警告が出ない（7b） |
| 2 | 同じ人で `/dashboard`・`/login` | 同上。`/dashboard/tenant` では警告が出る（従来どおり） |
| 3 | 決裁のホームのパンくず「ホーム」（後続の修正） | 決裁のホームへ・警告なし。基幹（E0001）のパンくずは基幹のダッシュボードへ |
| 4 | パスワード変更の画面をそのまま開く（直前の画面＝自分）→「キャンセル」の href | 決裁のみ＝決裁のホーム、基幹＝その人のダッシュボード（7a） |
| 5 | 決裁の管理者で CSV 取込（`.playwright-mcp/` に置いた 2 行の CSV）→ 確認 → 取り込む → 案内 →「元の画面へ戻る」（`beforeunload` の確認を受ける） | **取込の画面が開く**（405 でない。F2）・帯に「通知メール」が出ない（F7）・発行日（F5 は日本時間 0:00〜8:59 でないと差が出ないのでテストで担保） |
| 6 | 利用者の管理を「区分: 決裁のみ」で絞り込む | プルダウンを変えた瞬間に再読み込みされ、URL に `kind=approval`・「絞り込んだ全員（N 人）」が表の人数と一致（F8）。チェックも同様 |
| 7 | 同じ絞り込みのまま 1 人を再発行 → 案内 →「元の画面へ戻る」 | 絞り込みを保った一覧へ戻る（F2）・帯に「通知メール: 送る 0 人／送らない 1 人」（F7） |
| 8 | 利用者の管理の最終ログイン（A0005） | `2026/09/19 02:30`（F6） |
| 9 | 部門の管理（1440px） | 略称「不動産」とアルファベット「RE」が 1 行（文字の Range の `getClientRects().length === 1`。F3） |
| 10 | 部門の管理のドメインの行と「削除」 | 行は「…利用者: 2 人」だけ。削除を押すと確認のダイアログに人数と「届かなくなります」（`dialog.message()` で読み、**取り消す**）。0 人のドメインは質問だけ（F4） |
| 11 | 基幹の利用者管理で新規登録 → 案内 | 帯に「通知メール」が出ない（F7）・「元の画面へ戻る」で一覧へ |
| 12 | 変更した 4 画面 × 1800 / 1200 / 375px | `main.scrollWidth === main.clientWidth`（Bug #29） |

- [ ] **Step 3: 片付け**

```bash
git checkout -- routes/web.php
kill <serve の PID>; rm -f "$DB"; rm -f /Users/masanori/site/manage/.playwright-mcp/approval-fixes-*.csv
git status --porcelain   # 空
```

- [ ] **Step 4: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:cache
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:clear
```

期待: INVALID 0 件（本数を記録）。

---

## Task 15: 記録

- [ ] `docs/BACKLOG.md` の「決裁申請 段階1」の節: F1〜F8 を直したこと（取り込み 2 本・テスト本数・変異・実ブラウザ・lint）、「⚠ 未了」から F1〜F8 を外す、後続の節の「実ブラウザでの目視は未了」を更新。日本時間化・日付ピッカーの節に残る「`origin/13.x` への push はしていない」を「2026-09-24 に push 済み（`4fa0911e`）」へ
- [ ] 段階1 の計画書の「見つかった欠陥」の表に「→ 2026-09-24 に直した（この計画）」
- [ ] `docs/RULES.md` に 2 件:
  - **Bug #63** 行き先を `route('dashboard')` に固定すると、決裁のみ利用者は門番に跳ね返されて警告を見る・2 段の転送でフラッシュが消える（F1・パンくず・キャンセル・ホームの別名）→ `route($user->homeRouteName())`・門番はホームの別名だけ警告なし
  - **Bug #64** `url()->previous()` は**リファラーを優先**する。POST の応答の画面（確認画面）から送ると、戻り先が POST 専用の URL になり 405（F2）→ 戻り先は入口ごとに渡す。テストの HTTP クライアントはリファラーを送らないので `from()` を付けないと原理的に見えない
- [ ] `CLAUDE.md`: 「全 62 件」「Bug #1–62」→ 64 に、Laravel-specific quirks の User の行に「行き先は `route($user->homeRouteName())`（`route('dashboard')` にしない。Bug #63）」
- [ ] コミット: `docs: 決裁 段階1 の F1〜F8 を直した記録を残す`

---

## Task 16: 本番反映（段階1 計画の Task 17）

> ⚠ **親セッションが行う。サブエージェントに任せない。** 始める前に本文で次の 4 つの可否を確かめる（この計画の承認とは別）:
> ①本番の読み取り（ssh）②DB の変更（段階1 の SQL 12 文）③`13.x` への早送り・`composer install --no-dev`・`./deploy.sh` ④本番に残るサイドバーの死にファイル 3 本の削除（推薦: 消す。`deploy.sh` は `public/build/` 以外を消さないので rsync では消えない）
> あわせて「反映するとログイン画面が『社員番号またはメールアドレス』に変わる（全員に見える唯一の変化）」ことを伝える。

- [ ] **Step 1: 反映前の読み取り**（本番は csh なので `/bin/sh` の heredoc を流す・本番にファイルを置かない・PHP は `/usr/local/php/8.3/bin/php`）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
echo Illuminate\Support\Facades\DB::selectOne("SHOW CREATE TABLE `users`")->{"Create Table"}, PHP_EOL;
echo "rows=", Illuminate\Support\Facades\DB::table("users")->count(), PHP_EOL;
foreach (["approval_companies","approval_departments","approval_department_user","approval_members","approval_settings","approval_mail_domains","approval_setting_logs","cache","cache_locks"] as $t) { echo $t, "=", Illuminate\Support\Facades\Schema::hasTable($t) ? "あり" : "なし", PHP_EOL; }
'
SH
```

期待: `employee_number` が無い・`role` の enum に `approval_only` が無い・`approval_*` 7 表が「なし」・`cache` / `cache_locks` が「あり」（無いと 1 回限りの鍵とログイン制限が止まる。段階1 計画 Task 17 Step 2）。違ったら止めて相談する。

- [ ] **Step 2: DB を先に変える** — 引用符の事故を避けるため、SQL を JSON → base64 にして tinker の `--execute` に渡すシェルスクリプトをローカルで作り、ssh の標準入力で流す（本番にファイルを置かない。本番に `base64` コマンドは無いが PHP の `base64_decode` は使える）。

`$S/gen-apply.php`:

```php
<?php
// 本番へ流す SQL（12 文）を、tinker の --execute に渡すシェルスクリプトにする
$sqlPath = '/Users/masanori/site/manage/.claude/worktrees/approval-phase1/database/sql/2026-09-16-approval-phase1.sql';
$sql     = preg_replace('/^--.*$/m', '', file_get_contents($sqlPath));
$stmts   = array_values(array_filter(array_map('trim', explode(';', $sql)), 'strlen'));
if (count($stmts) !== 12) {
    fwrite(STDERR, '中断: 文の数が 12 でない（' . count($stmts) . "）\n");
    exit(1);
}
$b64 = base64_encode(json_encode($stmts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
// ⚠ すでに適用済みの形跡があれば何もしない。1 文ずつ流し、1 文ごとに先頭行を出す（失敗した文で止まる）
$php = <<<PHP
if (Illuminate\Support\Facades\Schema::hasColumn("users", "employee_number") || Illuminate\Support\Facades\Schema::hasTable("approval_settings")) { echo "中断: すでに適用済みの形跡がある", PHP_EOL; return; }
foreach (json_decode(base64_decode("{$b64}"), true) as \$i => \$s) { Illuminate\Support\Facades\DB::statement(\$s); echo "OK ", \$i + 1, ": ", strtok(\$s, "\\n"), PHP_EOL; }
PHP;
echo 'cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute=' . escapeshellarg($php) . "\n";
```

```bash
php "$S/gen-apply.php" > "$S/apply-approval-phase1.sh" && head -c 400 "$S/apply-approval-phase1.sh"; echo
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh < "$S/apply-approval-phase1.sh"   # OK 1〜12 が出ること
```

流した後に Step 1 の読み取りをもう一度流し、`users` に `employee_number`（一意索引つき）・`role` に `approval_only`・7 表が「あり」を確かめる。加えて `approval_companies` が 3 行・`approval_settings` が 1 行。

- [ ] **Step 3: （承認されたら）本番の死にファイル 3 本を消す**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage/resources/views/layouts/partials && ls -l sidebar_buyer_snippet.blade.php sidebar_contract_snippet.blade.php sidebar_housing_snippet.blade.php
SH
```

3 本とも在ることを見てから:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage/resources/views/layouts/partials && rm sidebar_buyer_snippet.blade.php sidebar_contract_snippet.blade.php sidebar_housing_snippet.blade.php && ls | grep -c snippet
SH
```

（`0` が出ること。`deploy.sh` の `view:cache` はまず `view:clear` するので、消した後に流せばコンパイル済みにも残らない）
- [ ] **Step 4: main repo で取り込み**

```bash
cd /Users/masanori/site/manage
git status --porcelain            # 空
git checkout 13.x
git merge-base --is-ancestor 13.x approval-phase1 && echo "FF できる" || echo "13.x が進んでいる（ブランチへマージしてから）"
git merge --ff-only approval-phase1
composer install --no-dev         # chillerlan/php-qrcode・php-settings-container が入る
ls vendor/bin/phpunit 2>/dev/null && echo "⚠ dev が混ざっている（deploy.sh が本番へ送ってしまう）"
composer dump-autoload --no-dev --optimize   # ⚠ main repo の cwd で（worktree で行うとパスが焼き込まれる）
```

- [ ] **Step 5: `./deploy.sh`**（exit 0 と、config / route / view のキャッシュ 3 つの成功を確かめる）

---

## Task 17: 本番の確認と記録・後片付け

- [ ] **ssh（読み取り）**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan tinker --execute='foreach (["App\\Support\\LoginId","App\\Support\\InitialPassword","App\\Support\\LoginQrCode","chillerlan\\QRCode\\QRCode"] as $c) { echo $c, "=", class_exists($c) ? "OK" : "なし", PHP_EOL; }'
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. | grep -c "approvals\."
ls resources/views/layouts/partials/ | grep -c snippet
SH
```

期待: `invalid=0` ／ 4 つとも OK ／ 19 ／ 0（Step 3 で消した場合）
- [ ] **ログイン画面**（未ログインで読める。`curl -s https://www.mitsuwat.co.jp/system/manage/index.php/login`）に「社員番号またはメールアドレス」
- [ ] **ログイン済みの実 Chrome**（claude-in-chrome。URL は `/system/manage/index.php/…`。フォームは送信しない）: 基幹の利用者管理が開き社員番号の列が出る ／ `/approvals/admin/users` が 403（管理者の指定の前）／ `/approvals` は「準備中」／ サイドバーに「決裁」が無い ／ コンソールのエラー 0
- [ ] **利用者にお願いすること**: メールアドレスで今までどおりログインできること（こちらはパスワードを入力しない）／ Safari での案内の印刷（段階1 計画 Task 16 の項目 8。稼働の前でよい）
- [ ] BACKLOG の段階1 の節を「本番反映済み」にして表を足し、コミット → `13.x` へ早送り（docs のみ。デプロイ不要）
- [ ] **後片付け（確認のうえ）**: `approval-followups` の worktree とブランチを消す（取り込み済み）。push は指示があったときだけ

---

## 範囲外（気づいたが、この計画では直さない）

- ~~CSV の確定（`execute`）の入力チェックが落ちたときの `back()` も、リファラー＝ POST 専用の preview へ戻る（405）。
  ただし確定のフォームは必ず `csv_data` を持つので、**改ざんした送信でだけ**到達する。記録だけ残す~~
  → レビューの Important 1（preview 側は通常の操作で踏む）と一緒に直した（`3ff938db`）
- F8 のあとも、検索語を打ちかけて「検索」を押さずに「絞り込んだ全員」を押すと、前回の検索語が対象になる
  （確認のモーダルに対象の氏名と注記が出るので気づける。打鍵ごとに送るのは避ける）
- 段階1 のレビューで残った Nit（`components/app-layout.blade.php` の死にコードなど。段階1 計画「そのほか」の ③④）
- Safari での案内の印刷の確認（利用者の手で。Task 17）
- （2026-09-24 に追加）**基幹の取込にも同じ 405 の形がありうる**（レビューの範囲外の指摘）。テナント・賃貸マンション・顧客・
  ZEAL 会員・周辺ビル・工程表の取込に、確認画面（POST の応答）から送る経路の `back()` が残っている。実行して確かめては
  いない。別の作業として提案した（Bug #64 の末尾にも書いた）
- （2026-09-24 に追加）ログイン案内は 375px で横にはみ出す（A4 の用紙 `.guide-page { width: 210mm }` をそのまま画面に出す
  印刷用の作り。この計画の前から）
- （2026-09-24 に追加）パスワード欄に `autocomplete="current-password"` / `"new-password"` が無い（Chrome の VERBOSE。
  段階1 の Task 16 でも記録した。パスワード管理ソフトの使い勝手の話）

## 検証のまとめ

- 全件テスト（Task 1・2・5・8・9・12 の後と最後）が緑。本数を記録
- 変異 24 通り（カナリア含む）がすべて期待どおりの集合と理由で落ちる（Task 13）
- ローカルの実ブラウザ 12 項目（Task 14）・コンパイル済みビューの lint INVALID 0
- 本番: 反映前の読み取り → DB → デプロイ → 読み取りの確認（Task 16・17）

---

## 実測記録（2026-09-24）

### 進め方と計画からの変更

- 計画どおりこの会話でインライン実行した。Task 0〜12 を順に行い、Task 12 の独立レビューの指摘（Important 1・Minor 6 件）を
  直してから Task 13・14 に進んだ
- ⚠ 変異テスト（Task 13）は作業用の worktree ではなく**隔離コピー**（`git worktree add --detach` ＋ vendor を `cp -Rc` で
  実体コピー。Bug #50）で、Task 14 の実ブラウザ確認と**並行して**流した。どのコピーでも最初にカナリア（M00）を当て、
  隔離コピーのコードが読まれていることを確かめてから測った
- レビューの指摘で直したもの（Important 1・Minor 2〜5）と、実ブラウザで再現した Minor 6 は、計画に無かったコミット（下の表）
- 計画では RULES に足すのは Bug #63・#64 の 2 件だったが、Minor 6（戻る操作での食い違い）を **Bug #65** として足した。
  番号は、書く前に 13.x の最新（`b146f6cd`）と照らして #63〜#65 が空いていることを確かめた（レビューの Minor 7）
- 作業中に 13.x が `4fa0911e` → `b146f6cd` に進んだ（別のセッションの docs のコミット 3 本。要件定義書・様式の調査・
  `docs/tools/`）。このブランチが触っていないファイルだけなので、本番反映の直前に取り込み直す（Task 16）

### コミットと全件テスト

| コミット | 内容 | 全件テスト |
|---|---|---|
| `b5378f7e` | 計画（Task 0）| OK (2068 tests, 14499 assertions)（着手前）|
| `36221db5` | `approval-followups` を取り込む（Task 1）| OK (2072 tests, 13726 assertions)。assertions の減少は、消した死にファイル 3 本を 1 行ずつ数えていた `LayoutSidebarDrawerTest::test_the_snippets_are_not_included_anywhere` の 772 → 1（JUnit の差分で確認）|
| `40786f01` | `13.x` を取り込む（Task 2）| 2122 tests・走査テスト 2 本だけが赤（予定どおり）|
| `c1bab094` `de518343` `b77ff824` `ef4dc49c` | F5・F6・通知メールの日時・走査の分類（Task 3〜5）| OK (2124 tests, 14177 assertions) |
| `ff3ffa2d` `7f76ec84` `2bad37ce` | F1・キャンセルの代わりの行き先・ホームの別名（Task 6・7）| OK (2137 tests, 14254 assertions) |
| `9fe7fda6` | F2（Task 8）| OK (2142 tests, 14323 assertions) |
| `8c250866` | F7（Task 9）| OK (2143 tests, 14330 assertions) |
| `86ffabe1` `2bb1df62` `5123c880` | F3・F4・F8（Task 10〜12）| OK (2145 tests, 14351 assertions) |
| `3ff938db` | レビュー Important 1: CSV 取込の確認画面から送り直して断られても取込の画面へ戻す | OK (2148 tests, 14364 assertions) |
| `ec6728da` | Minor 2: キャンセルはリファラーでなく直前の GET の画面へ | OK (2149 tests, 14368 assertions) |
| `c4fa9b10` `d81e8922` `55e8420f` | Minor 3〜5: テストの守りを固める（ホームの別名の集合・絞り込みの全件分類・ドメインの行ごとの人数）| テストの書き換えだけ（本数は同じ）|
| `695dbc78` | Minor 6: 戻る操作で戻ったときも絞り込みの表示を適用済みの条件に合わせる | **OK (2150 tests, 14379 assertions)** |

### 独立レビュー（Task 12 Step 6）

対象は `40786f01..5123c880` の 12 コミット。結論は「計画どおり。ただし F2 と同じ形の 405 が CSV 取込の確認画面に 1 か所残る」。
レビュアーは使い捨ての確認用テストで 405 の 3 経路を再現した（worktree には書き込んでいない）。

| # | 指摘 | 扱い |
|---|---|---|
| Important 1 | CSV 取込の確認画面からアップロードし直して断られると 405（ファイルの選び忘れ・xlsx・見出しだけ・行数の上限超え）。確認画面は POST の応答で URL は POST 専用。`back()` と入力チェックの既定の戻り先がリファラー＝ preview へ戻す | 直した（`3ff938db`）。preview と execute の入力チェック・`CsvImportException` の戻り先を取込の画面に固定。実ブラウザでも確かめた |
| Minor 2 | パスワード変更の「キャンセル」も `url()->previous()`（リファラー優先）のままで、CSV の確認画面のヘッダーから開くと 405 | 直した（`ec6728da`）。`session()->previousUrl()`（GET かつ Ajax でない要求だけが記録される）へ |
| Minor 3 | 門番の「ホームの別名」の集合が上から固定されていない（別名を `dashboard.executive` まで広げる・判定を 403 の前へ動かす変異が緑）| 直した（`c4fa9b10`）。全件分類で「警告なしで送る GET はちょうど `/` と `dashboard`」・Ajax の 403 を `/dashboard/tenant` `/dashboard` `/` で |
| Minor 4 | F8 のテストが select と checkbox しか拾わない（日付・ラジオを足しても緑）・検索欄は `onchange` しか見ない（`oninput` を足しても緑）| 直した（`d81e8922`）。名前を持つ入力を全件拾い、検索欄はイベントの属性を 1 つも持たないこと |
| Minor 5 | F4 の行の人数は「2 人」しか見ていない（全行を最大値にしても緑）| 直した（`55e8420f`）。行ごとに人数を見る |
| Minor 6 | （未検証）ブラウザの「戻る」で F8 の食い違いが再現しうる | **再現した**ので直した（`695dbc78`・Bug #65）。下の「Minor 6 の実測」 |
| Minor 7 | Bug #63・#64 がまだ RULES に無い（13.x が進むと番号がずれる）| 書く前に 13.x の最新と照らし、#63〜#65 が空いていることを確かめた |
| 範囲外 | 基幹の取込にも同じ `back()` の形がありうる | 実行はしていない。別の作業として提案した（「範囲外」に追記）|

### Minor 6 の実測

- 再現（Playwright・直す前）: 区分を「決裁のみ」にすると即時に送られ（URL `?kind=approval…`・表 5 行）、「戻る」で戻ると
  select は `approval`・まとめて再発行の hidden の `kind` は空・表は 7 行
- 戻り方の測定: 直す前の画面に `window.__marker` を置いてから区分を変えて戻ると、目印は消えていた
  （`performance.getEntriesByType('navigation')[0].type` は `back_forward`）＝ bfcache ではなく、**読み込み直してから入力欄の値を
  戻す**経路。アプリ内のブラウザでも同じだった
  ⚠ レビューが挙げた「`event.persisted` のときに `reset()`」は bfcache の経路しか直さないので、**この再現は直らない**。
  `pageshow` のたびに `reset()` する形にした（`persisted` に絞る変異 M24b をテストが止める）
- 直した後（Playwright）: 「区分を変えて戻る」「部門とチェックを変えて 2 回戻る」「進む」の 5 時点すべてで、select・checkbox の
  値と hidden が一致し、表の行数と「絞り込んだ全員（N 人）」も一致した
- アプリ内のブラウザ: 「戻る」で一致（`back_forward`）。bfcache の経路は、合成した `pageshow`（`persisted: true`）を送り、
  変えたままの select・checkbox・打ちかけの検索語がすべてサーバーが描いた値に戻ることで確かめた

### 変異テスト（Task 13）

作法は Bug #44 のとおり（コミット済みのコードに当てる・各変異の前後で `git status --porcelain` が空・置換は出現がちょうど 1 回の
ときだけ・`git diff --stat` で着弾を確認・全件を `--log-junit` で流す・落ちたテストの集合と**理由の文言**まで記録）。
実行役は `mutate.py`（scratchpad。戻しは `finally` で必ず行う）。隔離コピーは 2 つ: `55e8420f`（M01〜M23）と `695dbc78`
（M24 系。Minor 6 の修正の後に作った）。**48 通り（本体 41・M24 系 7。カナリア 2 回は別）すべてで狙ったテストが狙った文言で落ち、緑のまま通った変異は 0 件**。

| # | 変異 | 落ちたテスト（件数）と理由 |
|---|---|---|
| M00 | カナリア: 案内のビューに未定義の変数を出す | 48 件（案内を描く全テスト）。隔離コピー 2 つとも。＝ 隔離コピーのコードが読まれている |
| M01 | F1: 変更のあとを `route('dashboard')` に戻す | 8 件: 4 ロールの行き先・既存の行き先 2 本（`PasswordChangeTest`・`OtherDeviceLogoutTest`）・着いた画面の完了表示 2 本（決裁のみ「変更の完了が出ていない（転送の途中で消えた）」・基幹）|
| M02 | F1: 行き先を `approvals.home` に固定 | 5 件: 基幹 3 ロールの行き先・既存の行き先 2 本 |
| M03 | 7a: キャンセルの代わりの行き先を `route('dashboard')` に | 4 件: キャンセルの 4 ロール |
| M03b | Minor 2: キャンセルの直前の画面を `url()->previous()` に戻す（コントローラ）| 1 件: 「リファラー（POST 専用の URL）へ戻そうとしている」|
| M03c | 7a: ビューで `url()->previous()` を使う | 5 件: キャンセルの 4 ロール＋リファラーの 1 本 |
| M04 | 7b: 別名の分岐を消す | 4 件: ホームの別名 3 入口「ホームを開いただけなのに警告が出ている」＋全件分類「警告を出さずに決裁のホームへ送る GET が、ホームの別名とちょうど一致しない」|
| M05 | 7b: 別名を `dashboard` で始まる名前すべてに広げる | 2 件: 全件分類＋`/dashboard/tenant` で警告が出ること |
| M05b | 7b: 別名から `/` を外す | 2 件: 全件分類＋`/` の入口 |
| M05c | Minor 3: 別名に `dashboard.executive` を足す | 1 件: 全件分類（レビューが挙げた「生き残る変異」）|
| M05d | Minor 3: 別名の判定を 403 の分岐の前へ動かす | 1 件: 「Ajax の /dashboard が 403 で拒否されない」（302。レビューが挙げた「生き残る変異」）|
| M06 | F2: ビューを `url()->previous()` に戻す | 1 件: CSV の往復「「元の画面へ戻る」の行き先が違う」|
| M07 | F2: CSV の確定の戻り先を `url()->previous()` に | 1 件: 同上 |
| M08 | F2: 基幹の再発行の戻り先を一覧に固定（絞り込みが消える）| 1 件: 基幹の再発行の往復 |
| M08b | F2: 基幹の新規登録の戻り先を一覧に固定 | 1 件: 基幹の新規登録の往復 |
| M09 | F2: まとめて再発行の戻り先を一覧に固定 | 1 件: まとめて再発行の往復 |
| M09b | F2: 決裁の再発行の戻り先を一覧に固定 | 1 件: 決裁の再発行の往復 |
| M09c | Important 1: 確認画面の `CsvImportException` を `back()` に戻す | 1 件: 送り直し「見出しだけで行が無い」の 1 ケースだけ「取込の画面へ戻っていない（preview へ戻ると GET で 405）」＝ この経路を通るのはこのケースだけ |
| M09d | Important 1: 確認画面の入力チェックの戻り先を既定（リファラー）に戻す | 1 件: 送り直し「ファイルを選び忘れた」の 1 ケースだけ（入力チェックの経路）|
| M09e | Important 1: 確定の入力チェックの戻り先を既定に戻す | 1 件: 「確定の送信が壊れている」→「取込の画面へ戻っていない」|
| M10 | F5: `JapanTime::today()` を `now()` に戻す | 2 件: 発行日＋`ClockReadScanTest`（`LoginGuide.php:52 now(`）|
| M11 | F6: `JapanTime::format()` を直接の `->format()` に戻す | 3 件: 最終ログイン＋走査（`users/index.blade.php:212`）＋件数の下限（26 を下回った）|
| M12 | F7: CSV の確定で件数を渡す | 1 件: 「新規登録なのに通知メールの件数が出ている（F7）」|
| M12b | F7: 基幹の新規登録で件数を渡す | 1 件: 同上（基幹）|
| M13 | F7: 再発行で件数を渡さない | 4 件: 再発行の帯を見る 3 本＋入れ替わりを見る 1 本 |
| M13b | F7: 送る・送らないを入れ替える | 4 件: 同上（「送る人数と送らない人数が入れ替わっている」）|
| M14 | F3: 略称のセルから `whitespace-nowrap` を外す | 1 件: 「「不動産」のセルが折り返せる」|
| M15 | F3: アルファベットのセルから外す | 1 件: 「「RE」のセルが折り返せる」|
| M16 | F4: 行に「届かなくなります」を戻す | 1 件: 「行に「届かなくなります」が常に出ている」|
| M17 | F4: 削除の確認を質問だけに戻す | 1 件: 「mitsuwat.co.jp の削除の確認が違う」|
| M18 | F4: 0 人でも注意を出す | 1 件: 「zeal-mitsuwa.jp の削除の確認が違う」|
| M18b | F4: 確認の人数を別の行（先頭）の人数にする | 1 件: 「mitsuwat.co.jp の削除の確認が違う」|
| M18c | Minor 5: 行の人数を最大値にする | 1 件: 「dad-mitsuwa.jp の行の人数が違う」（レビューが挙げた「生き残る変異」）|
| M19 | F8: `department` の即時送信を消す | 1 件: 「即時に送らない絞り込みがある: <select name="department" …」|
| M20 | F8: チェックの即時送信を消す | 1 件: 同上（`never_logged_in`）|
| M21 | F8: フォームの `id` を消す | 1 件: 「絞り込みのフォーム（id="filter-form"）が無い」|
| M21b | Minor 4: 検索欄に `onchange` を足す | 1 件: 「検索欄が入力のたびに送る形になっている」|
| M21c | Minor 4: 検索欄に `oninput` を足す | 1 件: 同上（レビューが挙げた「生き残る変異」）|
| M21d | Minor 4: 即時に送らない日付の欄を足す | 1 件: 「即時に送らない絞り込みがある: <input type="date" …」（同上）|
| M22 | 分類から `OneTimeAction` を消す | 1 件: `ClockReadScanTest`（`OneTimeAction.php:55 now(`）|
| M22b | 分類の件数を 1 → 2 | 1 件: `StoredTimestampDisplayScanTest`（`Admin/UserController.php:399`）|
| M23 | 通知メールの日時を直接の `->format()` に戻す | 2 件: 「再発行の日時が日本時間で出ていない」＋件数の下限 |
| M24 | Minor 6: `pageshow` のリスナーを消す | 1 件: 「pageshow のリスナーがちょうど 1 つでない」|
| M24b | Minor 6: `event.persisted` のときだけ戻す | 1 件: 「bfcache から戻したときだけに絞っている」|
| M24c | Minor 6: 別のフォーム（まとめて再発行）を戻す | 1 件: 「pageshow のリスナーが絞り込みのフォームを元に戻していない」|
| M24d | Minor 6: `load` で待つ | 1 件: 「pageshow のリスナーがちょうど 1 つでない」|
| M24e | Minor 6: `document` で待つ | 1 件: 同上 |
| M24f | Minor 6: パース中に 1 回だけ戻す | 1 件: 同上 |
| M24g | Minor 6: script を実行されない型（`text/template`）にする | 1 件: 同上。⚠ 同じ script にある `approvalUsers()`（まとめて再発行の確認・編集のモーダル）も動かなくなる変異だが、**止めるのはこのテストだけだった**（それまでは無防備）|

### 実ブラウザ（Task 14）

使い捨ての SQLite ＋ `artisan serve`（127.0.0.1:8765）・Playwright（画面が見えている状態）。ログインは使い捨てのログイン用ルート
（コミットしていない。確認のあと `git checkout -- routes/web.php` で戻し、開発サーバ・DB・確認用のファイルも消した）。

| # | 見たこと | 結果 |
|---|---|---|
| 1 | 決裁のみの人で `/`・`/dashboard`・ログイン中の `/login` | どれも `/approvals` に着き、警告なし（7b）|
| 2 | 同じ人で `/dashboard/tenant` | 警告が出る（従来どおり）|
| 3 | パンくずの「ホーム」（後続の修正） | 決裁のみ → `/approvals`（警告なし）／ 経営層 → `/dashboard/executive` |
| 4 | パスワード変更の「キャンセル」 | 直前の GET の画面へ戻る。直前がこの画面自身ならその人のホーム。CSV の確認画面から開いても取込の画面へ（405 にならない）|
| 5 | CSV 取込（2 人）→ 確認 → 取り込む → 案内 →「元の画面へ戻る」 | 「2 人分」・通知メールの帯なし・発行日 2026年9月24日。閉じる前の確認を受けて**取込の画面が開く**（405 でない。F2・F7）|
| 5b | 確認画面からファイルを選ばずに送り直す ／ 見出しだけの CSV を送り直す | どちらも取込の画面に戻り、理由が出る（405 でない。レビュー Important 1）|
| 6 | 利用者の管理で絞り込みのプルダウン・チェックを変える | 変えた瞬間に送られ、URL・表・「絞り込んだ全員（N 人）」が一致（F8）。「戻る」でも一致（Minor 6。上）|
| 7 | 部門で絞り込んだ一覧から 1 人を再発行 → 案内 →「元の画面へ戻る」 | 帯に「通知メール: 送る 0 人／送らない 1 人」。戻り先は絞り込みつきの一覧（`?kind=&department=2&status=&search=`）で、押すとそこへ着き、表も「絞り込んだ全員（2 人）」も営業部の 2 人（F2・F7）|
| 8 | 最終ログイン（2026-09-18 17:30 UTC の人） | `2026/09/19 02:30`（F6）|
| 9 | 部門の管理（1440px）の略称とアルファベット | 「不動産」「RE」「営業」「SA」とも文字の Range の行数 1（F3）。スクリーンショットでも 1 行 |
| 10 | ドメインの行と「削除」 | 行は「…利用者: 1 人 ／ 2 人 ／ 0 人」だけで「届かなくなります」は無い。2 人のドメインの削除の確認は「このドメインを削除しますか。」＋空行＋「このドメインのメールアドレスを持つ利用者: 2 人（この人たちには通知メールが届かなくなります）」、0 人のドメインは質問だけ（どちらも取り消した。F4）|
| 11 | 基幹の利用者管理（検索「担当」で絞り込み）で新規登録 → 案内 | 通知メールの帯なし（F7）・社員番号 `m0901` → `M0901`・戻り先は絞り込みつきの一覧（F2）|
| 12 | 利用者の管理・部門の管理・パスワード変更 × 1800 / 1200 / 375px | 9 通りとも `main` と文書の横スクロール 0（`main` の幅 1580 / 980 / 375）|
| — | ログイン案内（印刷用の画面）| 1800・1200px は横スクロール 0。375px では A4 の用紙（`.guide-page { width: 210mm }` ＝ 794px）が横にはみ出す。**印刷用の作りで、この計画の前から**（帯と戻り先の変更とは無関係。「範囲外」に記録）|
| — | コンソール | エラーは、使い捨て DB に `ms_*` が無いことによる `/dashboard/executive` の 500 だけ（経営層でログインしたとき。記録済みの事象でこの計画とは無関係）。ほかはパスワード欄の `autocomplete` の VERBOSE のみ |

コンパイル済みビュー: **274 本 / INVALID 0 件**（`view:cache` → 1 本ずつ `php -l` → `view:clear`）。死にファイル 3 本を消した分、段階1 の 277 本から 3 本減った。
