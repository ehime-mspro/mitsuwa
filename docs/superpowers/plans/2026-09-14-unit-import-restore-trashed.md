# 区画の CSV 取込で削除済みの同名区画を扱う — 実装計画

worktree: `.claude/worktrees/unit-import-trashed-name`（ブランチ `unit-import-trashed-name` ＝ 13.x `8232997b` から分岐。作成・composer install 済み）
ベースライン: **1672 tests / 10292 assertions green**（`8232997b` で実測）

---

## Context（なぜ直すか）

Bug #58（削除した区画の参照）の範囲外として残した件。区画の CSV 取込（`Admin\TenantImportController::executeUnit()`）の重複チェックは
`Unit::where(物件, 表示名)` で**削除済みの区画を見ない**。削除済みと同じ名前の行はプレビューで「取込可能」になり、確定すると
一意制約 `idx_units_property_display`（物件＋表示名。削除済みの行も含む）で `SQLSTATE[23000]` になり、`catch` が `rollBack()` して
**正しい行も含めて全行が消える**（使い捨ての再現テストで確認。Bug #54 と同じ「1 行で全体が消える」型）。

- 契約・過去契約の取込は、削除済みの区画に「見つかりません。先に区画インポートを実行してください」と案内する。案内どおり区画の取込を流すと上の理由で失敗する行き止まり
- 画面からの区画の登録（`UnitController::store`）は削除済みの同名区画を**復元して上書き**するので安全（画面と CSV で結果が違う）
- テスト用スキーマに `units.usage_type_id` が無い（本番にはある。`database/sql/add_usage_type_id_to_units.sql` で足し、旧 `usage_type` は落とした）ので、
  **区画の取込の確定はテストで一度も通せていなかった**（`table units has no column named usage_type_id`）
- 本番の読み取り確認（2026-09-14・利用者の承認のうえ・書き込みなし）: 一意制約 `idx_units_property_display (property_id, display_name)` は**本番に実在**。
  削除済み区画 7 件（物件 12 の 2A 3A 4A 4B 5A 5B 5C）。どれも同名の生きている区画は無い ＝ 物件 12 の区画を取り込み直すと、この 7 つと同じ名前の行 1 つで取込全体が失敗する

利用者の判断（2026-09-14）:

- **削除済みの同名区画は復元して、CSV の行の内容で上書きする**（画面からの登録と同じ）。プレビューで予告し、その行も取り込む
- **過去契約の取込は、削除済みの区画にもそのまま取り込む**（プレビューで注意を出す。画面では Bug #58 の「5A（削除済み）」で出る）。
  区画の取込で復元すると、取り壊した区画が「空室」として生き返りフロアマップや入居率に混ざるため
- 契約中の契約の取込は、削除済みの区画には入れず、削除済みだと分かる理由を出す

---

## 方針

- **区画の取込**
  - 同名の区画を `Unit::withTrashed()` で 1 回引く。生きている → 今までどおりエラー行。削除済み → その行を**採用するときにだけ**（全部の検査を通った位置で）
    「復元する」と印を付け、`warnings` に予告を積む（途中で積むと、同じ行に「復元します」とエラーが並ぶ）
  - 予告の文言: 「物件「取込ビル」の区画「2A」は削除済みです。取り込むと復元して、この行の内容で上書きします（過去の契約・投資などもこの区画に戻ります）」
  - 確定時は、新規と同じ属性の配列で `fill()` → `restore()`（1 回の UPDATE）。CSV に無い `notes` は残す。状態は CSV の値（空欄なら空室）。
    削除済みの区画は契約中の契約を持たない（`UnitController::destroy` が止める）ので、状態が食い違うことはない
  - 復元した区画も `total_units` の数え直しに含める
  - プレビューの要約を「区画 N件を新規作成・M件を削除済みから復元」、完了メッセージを「区画インポート完了: N件を登録しました（うち削除済みから復元 M件）」にする（M=0 のときは今の文言のまま）
  - 確定のリクエストは `csv_data` から検査をやり直すので、復元する区画の id もサーバで計算し直す（ブラウザから id は受け取らない）
- **CSV の中の重複チェックを表示名で見る**: 今は生の `階|号室` で見ているので、`(3, A)` と `(空欄, 3A)` と `(03, A)` が同じ「3A」なのに素通りする。
  今は一意制約で全行が巻き戻り、復元を入れると同じ区画を 2 回上書きする。階の検査を重複チェックより前へ移し、表示名で比べる
  （1 行に複数の誤りがあるとき、どれを報告するかは変わる）
- **契約の取込**: 削除済みの区画にはエラー行で「物件「X」の区画「2A」は削除済みです。使う場合は、区画の取込で同じ区画を取り込むか、区画の画面から登録し直すと復元されます」（本当に無い区画は今の文言のまま）
- **過去契約の取込**: 削除済みの区画にもそのまま取り込む。採用するときに注意を積む（「区画「5A」は削除済みです。削除済みの区画のまま過去契約として取り込みます」）
- 区画タブの説明（`admin/tenant-import/index.blade.php`）に、削除済みの同名区画は復元されることを 1 行足す
- **テスト用スキーマ**: 新しい migration（`inquiry_usage_types` より後）で `usage_type` を落とし、`usage_type_id`（NULL 可）を足して本番の列と揃える。
  外部キーは付けない（SQLite では外部キーを足すとテーブルが作り直され、`units.status` の CHECK が消える）。本番の外部キー `fk_units_usage_type`（ON DELETE SET NULL）と
  正本 `database/sql/add_usage_type_id_to_units.sql` は docblock に書く。migration は SQLite のテストのための鏡（本番は raw SQL で管理）

## 変更するファイル

- `database/migrations/2026_09_14_000001_align_units_usage_type_id_with_live_schema.php`（新）
- `app/Http/Controllers/Admin/TenantImportController.php`（`executeUnit` / `executeContract` / `executePastContract`）
- `resources/views/admin/tenant-import/index.blade.php`（区画タブの説明 1 行）
- テスト: `tests/Concerns/SubmitsImportPreview.php`（新。`MansionImportTest` のプレビュー → 確定の往復ヘルパを、取込の URL を引数にして切り出す）・
  `tests/Feature/Admin/MansionImportTest.php`（切り出したヘルパを使う）・`tests/Feature/Admin/TenantUnitImportTest.php`（新）
- ドキュメント: `docs/RULES.md`（Bug #60）・`CLAUDE.md`・`docs/BACKLOG.md`・この計画書（**A（Bug #59）を取り込んでから**書く。番号と「全 N 件」がぶつかるため）

## 手順（TDD・1 関心事 1 コミット・各コミットで全テスト緑）

1. docs: この計画書
2. test(tenant): テスト用スキーマの区画に usage_type_id を足して本番と揃える（migration ＋ カナリア）
3. refactor(test): CSV 取込の往復ヘルパを trait に切り出す（`MansionImportTest` は緑のまま）
4. fix(tenant): 区画の取込で CSV 内の重複を表示名で見る
5. fix(tenant): 区画の取込で削除済みの同名区画を復元して上書きする
6. fix(tenant): 契約の取込で削除済みの区画をそれと分かる理由で止める
7. fix(tenant): 過去契約の取込で削除済みの区画にもそのまま取り込む
8. docs: 記録

## テスト（`TenantUnitImportTest`。すべて「プレビュー → 描画されたフォームをそのまま確定」の往復。Bug #54 ②）

- 素の往復で区画ができる（今まで一度も通っていなかった基準線）
- 削除済みと同名の行: `validCount` に入る ／ 予告は**役割（`viewData('warnings')`）と表示（`⚠ 行N: …` の行そのもの）を別々に**見る（要約にも「復元」が出るので素の `assertSee` は不可。Bug #54 ④）／
  確定で同じ id のまま `deleted_at` が空になり、CSV の値で上書きされる・`notes` は残る・ほかの行も入る・完了メッセージは完全一致
- 生きている同名の行はエラー行のまま、ほかの行は入る ／ 誤りのある行には「復元します」を出さない
- `(3, A)` と `(空欄, 3A)` の重複は 2 行目がエラー行になり、1 行目は入る
- `total_units` に復元した区画が入る（CSV に無いほかの削除済み区画は入らない）
- 契約の取込: 削除済みの区画は新しい文言のエラー行（本当に無い区画は今の文言＝対照）
- 過去契約の取込: 削除済みの区画に紐づいて入る（`unit_id` がその id）。注意は役割と表示を別々に見る
- 行き止まりの解消: 区画の取込で復元 → 同じ区画への契約の取込が通る
- カナリア: 削除済みの `3A` があると、生きている `3A` の INSERT は `UNIQUE constraint failed`（復元のテストはこの制約に依存する）。`units.status` の CHECK も残っている

## 検証

- 全テスト（worktree で `APP_KEY=... ./vendor/bin/phpunit`）＋ コンパイル済みビューの `php -l`
- **変異テスト**（Bug #44 の作法）: 同名の検出を「生きている区画だけ」に戻す ／ 予告を `continue` 付き・無しでエラーへ移す ／ 予告を見つけた位置で積む ／
  復元した行を数え直しに入れない・数え直しを `withTrashed()` に ／ 復元のときだけ 1 列を上書きしない・`notes` を消す ／ 重複のキーを生の `階|号室` に戻す ／
  要約・完了メッセージから復元の件数を落とす ／ 契約の文言・過去契約の紐づけを 1 つずつ戻す ／ migration から `usage_type_id`・一意制約を外す ／ 確定フォームの `confirmed`・`action`・`@csrf`
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright）: 削除済みの同名区画を含む CSV → プレビューの予告・要約 → 確定で完了メッセージ →
  区画詳細が復元されている ／ 契約の取込の文言 ／ 過去契約が「5A（削除済み）」で出る ／ コンソール 0 件
- **本番反映**（⚠ 利用者の明示承認のあと）: 13.x を取り込んでから FF マージ → `./deploy.sh` → コンパイル済みビューの `php -l`。本番で取込はしない（画面の確認は読み取りだけ）
