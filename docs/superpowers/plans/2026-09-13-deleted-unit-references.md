# 論理削除した区画を参照する画面の不具合 — 実装計画

worktree: `.claude/worktrees/deleted-unit-references`（ブランチ `deleted-unit-references` ＝ 13.x `8798ec7c` から分岐。作成・composer install 済み）
ベースライン: **1634 tests / 10083 assertions green**（この worktree で実測）

---

## Context（なぜ直すか）

区画（`App\Models\Unit`）は論理削除だが、削除の歯止めは「契約中の契約がある」だけ（`Tenant\UnitController::destroy()`）。
投資・修繕・問合せが付いた区画も削除できるのに、それらから区画へのリレーションが削除済みを読まない
（契約だけは `Contract::unit()` が `withTrashed()` 済み）。
その結果、区画を 1 つ削除した瞬間に次が起きる（コードで確認。本番では投資・修繕・問合せの参照が 0 件なので**今は発火していない罠**）:

| 系統 | 表示 | 編集・保存 |
|---|---|---|
| 投資 | 一覧・詳細・**物件詳細（ページ丸ごと）が 500**（`$inv->unit->display_name`）| 区画が選択肢に無く「区画は必須」で保存できない（無理に送ると `findOrFail` で 404）|
| 修繕 | 詳細・一覧・物件詳細で **「共用部」と誤表示** | 選択肢に無いので先頭の「共用部」が選ばれ、**どの項目を直して保存しても区画が共用部に書き換わる** |
| 問合せ | 希望区画が表示から**黙って消える**（全部消えると「未定」）| 保存すると `sync()` が中間テーブルから**消す** |
| 契約 | 落ちないが削除済みと分からない | —（削除済み区画の契約は必ず解約済みで、編集・賃料改定・解約の画面には入れない）|

本番の読み取り確認（2026-09-13・利用者の承認のうえ・書き込みなし）:
削除済み区画 7 件（すべて物件 12: 2A 3A 4A 4B 5A 5B 5C。2026-09-01 に削除）。
参照は **解約済み契約 1 件（C-1991-001・5A）だけ**。投資 0・修繕 0・問合せ 0。

利用者の判断:

- 削除済みの区画は「B1A（削除済み）」と表示し、区画ページ（404）へのリンクを外す
- **契約の画面にも同じ印を付ける**
- 区画の削除の歯止めは足さない

⚠ 先に「直す」と伝えた賃料改定画面の区画リンク（404）は、上記のとおり**到達しない**と判明したので触らない（表記の置き換えだけ行う）。

---

## 方針（このアプリの既存の流儀に揃える）

前例: 削除済みの担当者は `User::assignableWith($currentId)` で「現在の値だけ」選択肢に残し、`（削除済み）` を付ける。
買主も同じ（CLAUDE.md の Buyer の注意）。

1. **参照側のリレーションは `withTrashed()`**: `Investment::unit` / `Repair::unit` / `Inquiry::units` / `UnitRentRevision::unit`（BelongsTo・BelongsToMany のみ）。
   ⚠ `Property::units()`（HasMany）には付けない — フロアマップ・区画数・入居率に削除済みが混ざる
2. **表示は `Unit` の新しいアクセサ `display_label`** = 表示名 ＋ 削除済みなら `（削除済み）`
   （毎回 `trashed()` で判定。DB に保存しない — 同名で再登録すると `UnitController::store` が削除済みの行を復元するため）。
   `Repair::unit_label` / `Inquiry::unit_labels` もこれを使う。削除済みの区画には区画ページへのリンクを張らない（投資詳細・修繕詳細）
3. **編集の選択肢は「削除済みでない区画 ＋ そのレコードが今持っている区画」** —
   `Unit` にスコープ `includingTrashed(array $keepIds)`（`withTrashed()` のうえで `deleted_at IS NULL OR id IN (keepIds)`）を足し 3 か所で共用。
   ⚠ **列を絞る `get([...])` に `deleted_at` を必ず含める**（厳格モードが無いので、取り忘れると `trashed()` が黙って false になり印が消える）。
   ⚠ `withTrashed()` はクエリ全体の除外を外すので、問合せの「空室・商談中 OR 選択済み」はこのスコープと AND で組む（登録画面に削除済みが出ないように）
4. **入力チェック**: 登録は `Rule::exists('units', 'id')->withoutTrashed()`（404 や黙った紐付けをやめ「選択された区画は存在しません。」）。
   更新は「今の区画なら削除済みでも通す」 `Rule::exists(...)->where(fn ($q) => $q->whereNull('deleted_at')->orWhere/orWhereIn('id', 今の区画))`
   （閉じた条件は Laravel が括弧でくくる: `DatabasePresenceVerifier::addConditions` で確認済み）。
   その後の物件所属チェックは `Unit::withTrashed()`（問合せの 2 か所も）
5. **編集画面の Blade と Alpine は触らない**（ラベルはコントローラで作る。Bug #7 / #23 / #26 / #30 の地雷を踏まない）

---

## 変更するファイル

- モデル: `app/Models/{Unit,Investment,Repair,Inquiry,UnitRentRevision}.php`
- コントローラ: `app/Http/Controllers/Tenant/{InvestmentController,RepairController,InquiryController}.php`
- ビュー（`->display_name` → `->display_label`、削除済みはリンクなし）: 投資の一覧・詳細、修繕の詳細、
  物件詳細（契約タブ・解約タブ・投資タブ）、契約 6 画面（index / show / edit / revise / terminate / delete）、顧客詳細
- テスト: 新規 `tests/Feature/Tenant/DeletedUnitReferenceTest.php`（＋構造テスト）
- ドキュメント: `docs/RULES.md`（Bug #58）・`CLAUDE.md`（Top trap #18 と Buyer の注意の行に区画を追記）・`docs/BACKLOG.md`・この計画書

---

## 手順（TDD・1 関心事 1 コミット・各コミットで緑）

0. worktree 作成 → `composer install`（本体の vendor は触らない）→ この計画書をコミット
1. **fix(tenant): 投資の区画を削除済みでも読む** — `Unit::display_label`、`Investment::unit` に `withTrashed()`、投資の一覧・詳細（リンクなし）・物件詳細の投資タブ
2. **fix(tenant): 投資の編集で削除済みの区画を保てるようにする** — `Unit::includingTrashed()`、`buildUnitOptions($properties, array $keepIds = [])`（`deleted_at` を取得）、登録・更新の入力チェック
3. **refactor(tenant): 修繕の区画の選択肢の組み立てを 1 つにまとめる**（create / edit の重複。振る舞いは変えない）
4. **fix(tenant): 修繕の区画を削除済みでも読み、編集で共用部に書き換わらないようにする** — `Repair::unit` に `withTrashed()`、`unit_label`、詳細のリンク、選択肢に今の区画、入力チェック
5. **fix(tenant): 問合せの希望区画を削除済みでも読み、編集で消えないようにする** — `Inquiry::units` に `withTrashed()`、`unit_labels`、`buildVacantUnitOptions()` を `includingTrashed($selected)`（削除済みのチップに「商談中」を出さない）、入力チェック、物件所属チェック
6. **fix(tenant): 契約の画面で削除済みの区画に（削除済み）を付ける** — 契約 6 画面・物件詳細の契約／解約タブ・顧客詳細
7. **test(tenant): 区画への参照がすべて削除済みを読むことを固定する** — `UnitRentRevision::unit` に `withTrashed()` ＋ 構造テスト（下記）
8. **docs: …**（RULES / CLAUDE.md / BACKLOG / この計画書の結果）

---

## テスト（`DeletedUnitReferenceTest`。データは `UnitLabelDisplayTest` と同じくモデルを直接作り、区画を 1 つ論理削除する）

- **画面**: 投資の一覧・詳細、物件詳細（投資・修繕・問合せ・解約タブ）、修繕の一覧・詳細、問合せの一覧・詳細、
  契約一覧（すべて）・詳細・削除確認、顧客詳細が 200 で `B1A（削除済み）` を出す。修繕は `共用部` を出さない。
  投資・修繕の詳細に区画ページへのリンクが無い（`'href="' . route(...) . '"'` と閉じ引用符まで含めて見る。`/units/1` が `/units/12` に部分一致するため）。
  ⚠ `（削除済み）` は担当者の表示にも出るので、必ず区画名とセットで見る
- **選択肢**（Alpine が描くので HTML でなく `viewData`。`@json` は日本語を `\uXXXX` にする）:
  登録画面 3 つに削除済みが**出ない** ／ 編集画面には**今の区画だけ**出て、ほかの削除済みは出ない ／ ラベルは完全一致（例 `B1A（削除済み）（12.50坪）`）
- **保存の往復**: 送る値は手書きせず `viewData('allUnits')` / `viewData('selectedUnitIds')` から組み、
  保存後も `unit_id`・中間テーブルの行が残る（Bug #54 ②）。問合せは外して保存すると消える（外せること）
- **拒否**: 登録で削除済みの区画 → `unit_id`（問合せは `unit_ids.0`）のエラーで件数不変 ／
  更新でほかの削除済み区画 → エラー ／ 問合せで物件を変えつつ旧物件の削除済み区画を送る → エラー
- **フロアマップ**: `viewData('floorMap')` に削除済みの区画が入らない（`Property::units` を変えていないことの固定）
- **構造（全件分類）**: `app/Models` のソースから `belongsTo(Unit::class` / `belongsToMany(Unit::class` を持つメソッドを
  機械的に拾い（コメントを落として）、実際に呼んで `getQuery()->removedScopes()` に `SoftDeletingScope` が入っていること。
  拾えた数の下限（5）も固定

---

## 検証

- 全テスト（worktree で `APP_KEY=... ./vendor/bin/phpunit`）＋ コンパイル済みビューの `php -l`
- **変異テスト**（Bug #44 の作法: 先にコミット／前後で `git status --porcelain` が空／`git diff --stat` で着弾／落ちた理由の文言まで照合）:
  `withTrashed()` を 1 本ずつ外す ／ 印を外す ／ 3 か所の `get([...])` から `deleted_at` を外す ／ スコープの `whereNull` を外す ／
  更新の入力チェックを `withoutTrashed()` だけに ／ 登録の入力チェックを素の `exists` に ／ リンクの条件を外す ／
  問合せの所属チェックを `Unit::` に戻す ／ 契約の画面を 1 か所 `display_name` に戻す
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright）: 削除済みの区画を持つ投資・修繕・問合せ・解約済み契約を作り、
  ①各画面が開き「（削除済み）」が出る ②投資・修繕の編集で区画欄が削除済みの区画のまま表示され、状態だけ変えて保存しても区画が残る（修繕が共用部にならない）
  ③問合せの編集でチップがチェック済みで出て、そのまま保存で残る・外して保存で消える ④登録画面に削除済みの区画が出ない ⑤コンソール 0 件
- **本番反映**（⚠ 利用者の明示承認のあと）: FF マージ → `./deploy.sh`（DB 変更なし）→ コンパイル済みビューの `php -l`（ssh・読み取り）→
  実 Chrome で C-1991-001 の契約詳細・契約一覧（すべて）・物件 12 の物件詳細（解約タブ）・顧客詳細に「5A（削除済み）」

---

## 範囲外（案内だけ残す）

- **物件も同じ型**: 物件も論理削除で、投資・修繕・問合せ・区画の `property()` に `withTrashed()` が無い（物件の削除も契約中の契約しか止めない）。
  削除した物件の投資・修繕の詳細が 500 になりうる → 別タスク
- 区画の CSV 取込: 重複チェックは削除済みを見ないが DB の一意制約（物件＋表示名）は削除済みも含む →
  削除済みと同名の行があると取込全体が巻き戻る → 別タスク
- 契約の登録で、画面を開いたあとに区画が削除されると 404（`ContractController::store` の `findOrFail`）— 通常の操作ではまず起きない
- 区画の削除の歯止めを増やすこと（利用者の判断で行わない）
