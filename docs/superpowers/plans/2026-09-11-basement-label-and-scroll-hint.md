# 地下区画の表記（-1B1A）と横スクロールのヒントの修正 — 実装計画

worktree: `.claude/worktrees/basement-label-scroll-hint`（ブランチ `basement-label-scroll-hint` ＝ 13.x `382c11b7` から分岐。作成・composer install・vite build 済み）
ベースライン: **1347 tests / 9021 assertions green**（この worktree で実測）

---

## Context（なぜ直すか）

### 依頼 1: 地下の区画が「-1B1A」と出る

- **症状（ローカルで再現）**: テナント契約一覧で地下 1 階 A 号室が `-1B1A`（地上は `3A`・階なしは `A` で正しい）。
  物件詳細の契約タブも同じ。
- **原因**: 表示名 `display_name` は保存時点で既に階を含む（`Unit::generateDisplayName()` が `3A` / 地下は `B1A` を作る）。
  なのに表示側の 11 か所が「表示名が数字で始まらなければ階を前に付ける」をしており、`B` で始まる地下だけ `-1` が前置きされる。
  この書き方は最初のコミット（2046289d, 2026-04-08）からあり、**2026-04-13（8bd52915）に地下（階 -1〜-3）を足したとき直されなかった**。
- **本番（承認を得て読み取りのみ・2026-09-11）**: 区画 158 件（うち削除済み 7）。
  **表示名は全件 `generateDisplayName(階, 号室)` と一致**（食い違い 0・階なし 0・0 階 0）。
  前置きが働くのは**地下の 3 件だけ**（物件 8 の `B1A`、物件 13 の `B1A` `B1B`）＝ **正しいデータに一度も役立っていない**。
  地下の区画を参照する契約・問合せ・投資・修繕は現在 0 件。表示名を書く経路は区画の登録・編集と CSV 取込の 2 つだけで、どちらも同じ規則。
- **利用者の回答で一緒に直すもの**:
  - 修繕の登録・編集の「区画」選択肢 — `($u->floor ?? '') . $u->display_name` が**条件なしで**階を付けるので、
    本番の削除されていない 151 区画すべてが崩れている（地上 148 区画は `11A` `33A` 型・地下 3 区画は `-1B1A`）
  - 物件詳細のフロアマップの階ラベル — 地下が `-1F` → **`B1F`**（周辺ビル調査の `AreaBuildingTenant::floorLabel()` と同じ書き方）

### 依頼 2: 「横にスクロールして全項目を表示」が最初に出ない

- **症状（ローカルで 3 画面とも再現）**: スクロールできるのにヒントも右端のフェードも `display: none`。

  | 画面 | 幅 | scrollWidth / clientWidth | ヒント |
  |---|---|---|---|
  | 賃貸マンション 部屋契約一覧 | 1100px | 880 / 814 | 出ない |
  | ZEAL 会員一覧 | 1100px | 860 / 814 | 出ない |
  | ZEAL 体験予約一覧 | 1280px | 1109 / 994 | 出ない |

- **原因（一時的な計測で直接確認・コードは戻した）**: 唯一の初回呼び出し `update();` は
  `readyState: loading`・Alpine 未起動・`aside[x-cloak]` 3 本の瞬間に走り、領域を **1034px**（サイドバー 220px 分広い）と測って「不要」と判定。
  DOMContentLoaded の時点では Alpine 起動済み・x-cloak 0 本で **880 > 814**。以後は scroll / resize でしか測り直さない。
  工程表ボード（36b86b80）と同じ仕組み。症状が出るのは画面幅が「表の実幅 + 66」〜「+ 286」px の範囲（1024px 以上）。
- **最初の描画は DOMContentLoaded より後**（Playwright の描画するヘッドレス Chromium で 6 回実測: FCP 76〜96ms / DCL 48〜57ms）
  → DOMContentLoaded まで待たせても「一瞬出て消える」は起きない（サイドバーが出るのも同じ瞬間）。

---

## 方針

### 依頼 1: 表示名をそのまま出す（採用）

| 案 | 評価 |
|---|---|
| **A 表示名をそのまま出す（採用）** | 本番で全件正しい形。**計算そのものが消える**ので 11＋2 か所に散った計算（Bug #41 の型）が無くなる。区画詳細・区画一覧・顧客詳細・賃料改定のパンくずなど、既に表示名を直接出している 20 か所以上と揃う（今は賃料改定画面の中でパンくず `B1A` と本文 `-1B1A` が食い違っている） |
| B 階と号室から毎回組み立てる | データの形に依存しないが、表示名とは別の「区画ラベル」が生まれて他画面と食い違う余地を作る。選択肢のクエリに号室の列も要る |
| C 前置きを地下に対応させる | 本番の正しいデータでは一度も働かない処理を残す |

- ビューは `$dn = …; $unitLabel = …;` の 2 行を **`$unitLabel = <表示名>;` の 1 行**にする（null 安全の書き方は各画面のまま。残りのマークアップは不変）
- フロアマップは `buildFloorMap()` の 1 行: `(is_int($floor) && $floor < 0 ? 'B' . abs($floor) : $floor) . 'F'`
  （`groupBy('floor')` は階なしを `''` のキーにする。`'' < 0` は PHP 8 で true になり `abs('')` が TypeError になるので `is_int` で守る。階なしの挙動「F」は不変）
- `Unit::generateDisplayName()` の docblock に「表示名は階を含む（地下は B1）。画面で階を前に付けない」を追記（コードは不変）

### 依頼 2: 初回の計測を DOMContentLoaded まで待たせる（採用）

- 3 画面とも `update();` を **`document.addEventListener('DOMContentLoaded', update);`** に置き換えるだけ（関数 `update` と式は不変）。
  前例 36b86b80・`tenant/units/index.blade.php`・`resources/js/app.js` の共通ヒントと同じ待ち方。説明は Blade コメントに書く（Bug #30）
- 採らない案: `ResizeObserver`（描画に結び付いて非表示タブで止まる・前例と違う・サイドバー開閉まで範囲が広がる）/
  `requestAnimationFrame`（非表示タブで止まり Alpine より後の保証も無い）/
  パース中の計測を残して DCL でも測り直す（パース中の値は 1024px 以上で必ず 220px 広い誤った値。上の実測で残す理由も無い）

---

## 変更するファイル

**新しい PHP クラス・DB 変更・ルート変更は無し**（`composer dump-autoload` 不要）。

| 区分 | ファイル |
|---|---|
| 依頼 1 | `resources/views/tenant/contracts/{index,show,edit,revise,terminate,delete}.blade.php` ／ `resources/views/tenant/properties/show.blade.php`（契約中・解約済みの 2 か所）／ `app/Models/Inquiry.php`（`getUnitLabelsAttribute` → `pluck('display_name')->implode(', ')`）／ `app/Http/Controllers/Tenant/{InquiryController,InvestmentController}.php`（選択肢 = 表示名＋（坪））／ `app/Http/Controllers/Tenant/RepairController.php`（create・edit の 2 か所）／ `app/Http/Controllers/Tenant/PropertyController.php`（フロアマップ）／ `app/Models/Unit.php`（docblock のみ） |
| 依頼 2 | `resources/views/mansion/contracts/index.blade.php` ／ `resources/views/zeal/inquiries/index.blade.php` ／ `resources/views/zeal/members/index.blade.php` |
| テスト（新規） | `tests/Feature/Tenant/UnitLabelDisplayTest.php` ／ `tests/Feature/LayoutMeasuringScriptTest.php` |
| ドキュメント | `docs/superpowers/plans/2026-09-11-basement-label-and-scroll-hint.md`（この計画＋結果）／ `docs/RULES.md`（Bug #56・#57）／ `docs/BACKLOG.md` ／ `CLAUDE.md`（Top traps に 1 行＋件数表記。**不要なら承認時に外してください**） |

---

## Task 0: 準備

- この計画を `docs/superpowers/plans/2026-09-11-basement-label-and-scroll-hint.md` に置いてコミット（`docs(plan): …`）
- 検証環境は作成済み（scratchpad の使い捨て SQLite 2 本・`ZEAL_DB_URL` で zeal 接続も SQLite へ・`artisan serve --port=8123`）。
  `routes/web.php` 末尾の使い捨てログインルートと SQLite 用 `DATE_FORMAT` は**コミットしない**（Task 5 の最後に `git checkout -- routes/web.php`）
- main repo の `.playwright-mcp/page-2026-09-11T14-46-09-504Z.yml`（今回の計測で出たもの。gitignore・deploy 除外済み）を削除

## Task 1: 依頼 1 — 区画の表記（TDD。1 関心事 1 コミット、各コミットで緑）

`tests/Feature/Tenant/UnitLabelDisplayTest.php`（`RefreshDatabase`・経営層ユーザー）。フィクスチャ:
ビル型（`total_floors` 3）に `B1A`(-1) `B1B`(-1) `1A` `3A`、平屋に `A`（階なし）。契約中: B1A / 3A / A、解約済み: B1B。問合せ（B1A, 3A）・投資（B1A）・修繕 1 件。

⚠ **`assertSee('B1A')` は `-1B1A` にも部分一致して素通りする**（Bug #43 の型）→ 必ず前後の文脈ごと見る＋`assertDontSee('-1B1A')` を対で置く。

| コミット | 足すテスト（先に書いて赤を確認 → 直して緑） | 直すもの |
|---|---|---|
| 1a `fix(tenant): 区画の表記で表示名の前に階を付けない` | 契約一覧（`テストビル / B1A` `テストビル / 3A` `テスト平屋 / A`）／ 詳細（区画の div の中身が `B1A` だけ）／ 編集（`value="B1A（`）／ 賃料改定・解約・削除確認（`テストビル / B1A`）— **画面ごとに 1 本** ／ 物件詳細の契約中・解約済みの行（**契約番号のリンク → 日付セル → 区画セル**を 1 本の正規表現で辿る。フロアマップの `B1A` に誤って当たらないように）／ 問合せの `unit_labels`（`B1A, 3A`）と問合せ詳細 ／ 問合せ・投資の登録画面の `viewData('allUnits')` のラベル（`B1A（12.50坪）` 等） | ビュー 8 か所・`Inquiry`・問合せ／投資の選択肢 |
| 1b `fix(tenant): 修繕の区画選択で階が 2 回付く（11A / 33A）を直す` | 修繕の**登録と編集を別々に**: `viewData('allUnits')` のラベルが `B1A B1B 1A 3A A` の集合と一致（`11A` `33A` `-1B1A` を含まない） | `RepairController` 2 か所 |
| 1c `fix(tenant): フロアマップの地下の階を B1F と表示する` | `viewData('floorMap')['floors']` のラベルが `['3F', '1F', 'B1F']` の順 ／ 画面に `B1F` があり `-1F` が無い | `PropertyController::buildFloorMap()` |
| 1d `test(tenant): 表示名の前に階を連結する書き方を走査で止める` | `app/**/*.php` と `resources/views/**/*.blade.php` に「階を表示名の前に連結する」書き方が 0 件（パターン `floor … . $dn / $displayName / …->display_name` と `preg_match('/^\d/'`）。**空振り防止**: パターンが既知の悪い 3 形に当たることの自己テスト＋走査ファイル数の下限 400（今 472） | なし（1a〜1c 後に緑。今のコードでは 13 件＋11 件に当たることを確認済み） |

- `Unit::generateDisplayName()` の docblock 追記は 1a に含める
- ⚠ 走査が守るのは**既知の書き方だけ**（変数名が違えば素通り）。画面ごとの挙動テストが本体で、走査は再発防止の補助と明記する

## Task 2: 依頼 2 — 初回の計測（TDD・1 コミット）

`tests/Feature/LayoutMeasuringScriptTest.php`（Blade ソースの構造テスト。3 画面の script には Blade 式が無いのでソース＝出力。
ZEAL の 2 画面は MySQL 専用の `DATE_FORMAT`・外部の zeal 接続のせいで、テストで描画するにはテスト専用の細工が要る）:

1. **`test_every_layout_measuring_script_is_classified`**（全件分類。Bug #45 ① / Top trap #13）
   全 Blade の**インライン** `<script>` から Blade コメント・JS コメントを落とし、`.scrollWidth` `.clientWidth` `.offsetWidth`
   `.getBoundingClientRect` `.scrollLeft` を読むものを列挙 → 分類表のキーと**完全一致**（今は 11 本）。空振り防止に走査した script 数の下限 80（今 96）。
   - **初回を DCL まで待つ**（初回の計測関数名つき）: `mansion/contracts/index`・`zeal/inquiries/index`・`zeal/members/index`（`update`）／ `tenant/units/index`（`checkScroll`）／ `_partials/_schedule_board`（`scheduleBoardSetInitialScroll`）
   - **利用者の操作の後にだけ測る**（理由をコメント）: `_partials/_schedule_section`（保存の応答後）／ `buyers/index`・`housing/custom-orders/index`・`housing/properties/index`・`realestate/procurements/index`・`realestate/projects/index`（バッジのクリック時）
2. **`test_initial_measurement_waits_for_dom_content_loaded`**（上の「DCL まで待つ」5 本すべて）
   計測関数の**呼び出し**（定義 `function 名(` を除く）がすべて DOMContentLoaded リスナーの本体の中にある（波括弧の対応で切り出す。
   `ScheduleBoardTest::braceBody()` と同じ方式）、かつ DCL に登録されている（本体の中で呼ぶ or `addEventListener('DOMContentLoaded', 名)`）。`document` / `window` どちらでも可

赤（3 画面ぶん。落ちる理由の文言まで確認）→ 3 画面を直す → 緑 → コミット `fix(list): 横スクロールのヒントを DOMContentLoaded の後に判定する`

## Task 3: 全テストとコンパイル済みビュー

- `APP_KEY=… ./vendor/bin/phpunit` → 1347 + 追加分がすべて緑
- `php artisan view:cache` → 全ビューを `php -l` → INVALID 0 → `view:clear`（Bug #21 / #26 / #30）

## Task 4: 変異テスト（Bug #44 の作法: 先にコミット → 各変異の前後で `git status --porcelain` が空 → `git diff --stat` で着弾確認 → 落ちた理由の文言まで照合。置換は「ちょうど 1 か所に当たらなければ止まる」小さな道具で）

先にカナリア（確実に赤くなる変異）で測定装置が worktree のコードを読んでいることを確認。予定:

- 依頼 1: 旧い前置きへ戻す × 13 か所（ビュー 8・`Inquiry`・問合せ／投資・修繕 create／edit）→ それぞれ対応するテストだけが赤 ／ 区画を出さない（`{{ '' }}`）→ 赤（肯定側のアサートが効いていること）／ フロアマップを `$floor . 'F'` へ戻す・地上にも B を付ける → 赤 ／ どこかに `$u->floor . $u->display_name` を足す → 走査が赤
- 依頼 2: 3 画面それぞれ `update();` へ戻す → 赤 ／ DCL と即時の両方 → 赤 ／ `requestAnimationFrame(update)`・`load`・イベント名の打ち間違い → 赤 ／ `window.addEventListener('DOMContentLoaded', update)` → 緑（等価変異）／ `tenant/units/index` の `checkScroll();` をリスナーの外へ・工程表ボードの呼び出しを外へ → 赤 ／ 分類されていない Blade に `el.clientWidth` を足す → 分類テストが赤

結果は plan doc に表で記録（検出 / 当初検出漏れ→追加で検出 / 等価変異 を区別）。

## Task 5: ローカル実ブラウザ確認（`preview_start` は使わない。起動済みの `artisan serve` と使い捨てルート）

- 依頼 2: 3 画面 × 幅 **1024 / 1100 / 1180 / 1280 / 1366 / 1440 / 1800 / 375** で読み込み直し、
  `(scrollWidth − clientWidth > 2) === ヒントが見える === フェードが見える` を全通り確認（修正前は 1100・1280 で食い違うことを確認済み）。右端までスクロールでフェードが消える
- 依頼 1: 契約 6 画面・物件詳細（契約中／解約済み／問合せタブ／フロアマップ `B1F`）・問合せ一覧／詳細／登録・投資登録・修繕登録／編集で表記を目視
- `main.scrollWidth === main.clientWidth`・コンソール出力 0 件
- 終わったら `git checkout -- routes/web.php`、サーバ停止、scratchpad の検証用ファイル削除

## Task 6: ドキュメント（1 コミット `docs: …`）

- plan doc に変異テスト・ブラウザ確認の結果
- `docs/RULES.md` — **Bug #56**: 幅・スクロール位置をパース中に測る（x-cloak のサイドバー 220px。工程表ボード＋3 一覧。分類テスト）／ **Bug #57**: 表示名の前に階を付ける（地下 `-1B1A`・修繕 `33A`・本番データの実測）
- `docs/BACKLOG.md` — 節を追加。「テナント契約一覧」節の「⚠ 地下の区画が `-1B1A` と出る既存の表示の癖（今回は直していない）」を解消済みに
- `CLAUDE.md` — Top traps に #17（パース中の計測）＋「全 55 件」「Bug #1–55」の件数表記を 57 に（**任意**）
- memory — x-cloak のメモを更新（3 一覧を修正・分類テスト名）／「本番の区画表示名は generateDisplayName と全件一致（2026-09-11）」を追加

## Task 7: 本番反映（⚠ 各段で利用者の明示承認を得てから）

1. main repo で `git checkout 13.x && git merge --ff-only basement-label-scroll-hint` → `./deploy.sh`
2. ssh で本番のコンパイル済みビューを `php -l`（`/usr/local/php/8.3/bin/php`・csh なので `/bin/sh` へ heredoc。読み取りのみ）
3. ログイン済みの実 Chrome（claude-in-chrome。URL は `/system/manage/index.php/...`）で:
   物件 8・13 のフロアマップが `B1F` ／ 修繕の登録画面の選択肢に `11A` 型が無い ／ 投資の登録画面で地下が `B1A（…坪）` ／
   3 一覧を「表の実幅 + 66〜286」の幅で開いてヒントが出る・広い幅では出ない ／ FCP と DCL の順序 ／ コンソール 0 件
4. BACKLOG に本番反映を記録してコミット。**origin への push は指示があったときだけ**

---

## 範囲外（気づいたが直さない）

- サイドバーの「閉じる」／開くでは `update()` が走らない → 開閉後はヒントが古いまま（スクロール不要なのに出る側にだけずれる）。初回表示の件ではない
- `UnitController::generateDisplayName()` は `Unit::generateDisplayName()` の複製（中身は同一。今は表示に影響なし）
- テナント CSV 取込の重複エラー文の「（-1階）」（利用者が CSV に書いた値そのままの表記）
- 階なしの区画を持つビル型物件でフロアマップの階ラベルが「F」だけになる（本番 0 件）

## コミットの雛形（すべて末尾にトレーラー）

```
fix(tenant): 区画の表記で表示名の前に階を付けない

表示名は保存時点で階を含む（地下は B1A）のに、11 か所が「数字で始まらなければ
階を前に付ける」をしていて地下だけ -1B1A になっていた。本番 158 区画で表示名は
generateDisplayName と全件一致し、前置きが働くのは地下の 3 件だけだった。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
