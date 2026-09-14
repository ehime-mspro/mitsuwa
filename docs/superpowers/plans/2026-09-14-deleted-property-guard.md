# 削除した物件を参照する画面の不具合 — 実装計画

worktree: `.claude/worktrees/deleted-property-references`（ブランチ `deleted-property-references` ＝ 13.x `8232997b` から分岐。作成・composer install 済み）
ベースライン: **1672 tests / 10292 assertions green**（この worktree で実測）

---

## Context（なぜ直すか）

Bug #58（削除した区画の参照）の範囲外として残した件。物件（テナント `App\Models\Property`）は論理削除だが、削除の歯止めは
「契約中の契約がある」だけ（`Tenant\PropertyController::destroy()`）。区画・解約済み契約・投資・修繕・問合せがある物件も消せて、何も連鎖しない。
子から物件へのリレーション（Unit / Investment / Repair / Inquiry / Transaction / PropertyChangeLog）は `withTrashed()` を持たない（Contract だけ持つ）。

使い捨ての再現テスト（30 画面）と静的調査で確認した症状:

| 系統 | 症状 |
|---|---|
| 500 | 投資・修繕（区画あり・共用部とも）・問合せの詳細、問合せの編集、区画の詳細・編集・賃料改定、区画の更新（保存前に落ちる）・区画の削除（**削除が保存された後に**落ちる）、顧客詳細（その顧客の問合せが削除した物件を指すとき）— 計 11 か所 |
| 黙って消える | 投資・修繕・問合せ・区画の一覧から、削除した物件の分が消える（`whereHas('property')` が削除済みを除く）|
| 編集 | 投資・修繕の編集画面で、物件の選択肢に今の物件が無い（保存するには別の物件へ付け替えるしかない）|
| 登録 | `exists:properties,id` は論理削除を見ないので、手組みの送信で削除済みの物件に投資・修繕・問合せ・契約を作れる（作られたレコードは一覧に出ず、詳細は 500）|
| 契約 | 落ちないが削除済みと分からず、物件ページ（404）へのリンクが残る |

本番の読み取り確認（2026-09-14・利用者の承認のうえ・書き込みなし）: 物件 17 件のうち削除済みは 1 件（T-009 No.20ミツワビル・非稼働・2026-06-25 に削除）。
関連データは区画・契約・投資・修繕・問合せ・取引すべて 0 件（物件の変更履歴 2 件だけ）＝ **本番では発火前の罠**。

利用者の判断（2026-09-14）: **削除を止める**。区画・契約（解約済みも含む）・投資・修繕・問合せが残る物件は削除させず、「非稼働」を案内する。
登録・更新で削除済みの物件は受け付けない。画面側の `withTrashed()`・印付けはしない（区画の Bug #58 とは別の方式）。

---

## 方針

- **守る不変条件: 「削除済みの物件は、生きている区画・契約・投資・修繕・問合せを持たない」**。①削除の歯止め ②登録・更新の入力チェック、の 2 つで守る
- 他の経路から削除済みの物件に子が付くことはない（確認済み）: 区画の登録はルートで物件を受けるので 404。CSV 取込 4 種は既定のスコープで物件を引く。
  Ajax 2 本（空き区画・問合せ）もルートで受ける。`restore()` は生きている物件の下でしか呼ばれない（`UnitController::store`）
- **画面の 11 か所には手を入れない**（不変条件のもとでは到達しない）。後から Top trap #18 に従って `withTrashed()` を足されないよう、理由を `Property` の docblock と CLAUDE.md に書く
- 削除時のロックはかけない（子を書く側は 7 つの入口と 3 つの取込がロックなしで確かめるので `destroy` だけ固めても競合は閉じない。物件 17 件・削除は経営層だけ・SQLite では確かめられない）。理由は `destroy()` のコメントに残す（Bug #48 の末尾）
- 数えない関連: 変更履歴（物件ページでしか出ない）・取引（`TransactionController` にルートが無い）・添付（`AttachmentController` の TYPE_MAP に物件が無く添付する経路が無い）

## 変更するファイル

- `app/Models/Property.php` — `DELETION_BLOCKING_RELATIONS`（`units` 区画 / `contracts` 契約 / `investments` 投資 / `repairs` 修繕 / `inquiries` 問合せ）・
  `DELETION_IGNORED_RELATIONS`（`changeLogs` / `transactions` / `attachments` と理由）・`deletionBlockers(): array`（`['区画 2 件', '投資 1 件']`。論理削除済みの子は数えず、0 件の種類は出さない）・docblock
- `app/Http/Controllers/Tenant/PropertyController.php::destroy()` — 「契約中の契約」チェックを置き換える。文言は `App\Support\DeletionBlockers::summarize()` の書き方に揃え、
  「この物件には区画 2 件・投資 1 件があるため削除できません。使わなくなった物件は、編集画面で稼働状態を「非稼働」にしてください。」（「非稼働」は `OperationStatus::Inactive->label()`）。返し方は今と同じ `back()->with('error', …)`
- 入力チェック 7 か所の `property_id` を `['required', Rule::exists('properties', 'id')->withoutTrashed()]` に:
  契約の登録（`ContractController`）・投資／修繕／問合せの登録と更新（`InvestmentController` / `RepairController` / `InquiryController`）。文言は既存の「選択された物件は存在しません。」
- テスト: `tests/Concerns/ScansModelRelations.php`（新。`DeletedUnitReferenceTest` の `modelClasses()` / `methodSourceWithoutComments()` を切り出す）・`tests/Feature/Tenant/PropertyDeletionGuardTest.php`（新）
- ドキュメント: `docs/RULES.md`（Bug #59）・`CLAUDE.md`（Top trap #18 の末尾・Laravel-specific quirks・「全 58 件」）・`docs/BACKLOG.md`・この計画書

## 手順（TDD・1 関心事 1 コミット・各コミットで全テスト緑）

1. docs: この計画書
2. refactor(test): モデルのリレーション走査を trait に切り出す（振る舞いは変えない）
3. fix(tenant): 関連データが残る物件は削除せず非稼働を案内する
4. test(tenant): 物件のリレーションを全件分類して削除の歯止めの漏れを防ぐ
5. fix(tenant): 投資・修繕・問合せ・契約の保存で削除済みの物件を受け付けない
6. docs: 記録（RULES / CLAUDE.md / BACKLOG / この計画書の結果）

## テスト（`PropertyDeletionGuardTest`）

- **種類ごとに止まる**（Bug #44: 全種類を 1 本ずつ）: 生きている区画／契約中の契約／解約済みの契約／投資／修繕／問合せ。物件が残ること・文言の完全一致。
  `contracts.unit_id` / `investments.unit_id` は NOT NULL なので、1 種類ずつ測るときは削除済みの区画を指させる
- 全種類があるときの文言（並び順）／論理削除済みの子だけなら削除できる（種類ごと）／数えない 3 種だけなら削除できる／関連データが無ければ削除され一覧へ戻る
- **画面からの往復**: 物件詳細が描画した削除フォームを `ParsesForms::parseForm()` で分解して送り返し（DELETE・action・`_token`）、続けて詳細を描画して
  **赤帯の要素の中**に文言がちょうど 1 回出ること（Bug #49: 描画の前にセッションを触らない ／ Bug #43・#46: ページ全体の `assertSee` にしない）
- **入力チェック 7 本**: 削除済みの物件を送ると `property_id` に「選択された物件は存在しません。」が出て、何も書かれない・変わらない。
  ⚠ 投資と契約は区画の所属チェックが先に弾くので（Bug #48）、**物件を `delete()` で直接消し、その下に生きている区画を残した状態**を作る
- **構造（全件分類・両側から）**: 物件の側は `app/Models/Property.php` のリレーションをコメントを落として拾い、ちょうど 1 つのリストに入ること・リストに実在しない名前が無いこと・下限。
  子の側は `Property::class` を指すリレーション（`HsProperty` / `MsProperty` を除く後読み）を持つモデルが、分類済みのリレーションの相手に含まれること・下限。
  判定は `getFileName()`（`getDeclaringClass()` は trait のメソッドで当てにならない）

## 検証

- 全テスト（worktree で `APP_KEY=... ./vendor/bin/phpunit`）＋ コンパイル済みビューの `php -l`
- **変異テスト**（Bug #44 の作法: 先にコミット／前後で `git status --porcelain` が空／`git diff --stat` で着弾／落ちた理由の文言まで照合。最初にカナリア）:
  `destroy` を「契約中だけ」に戻す ／ 0 件の種類を出す ／ ラベルの入れ替え ／ 定数の並び ／ `> 1` ／ 問合せを「数えない」側へ（構造は緑・振る舞いが赤）／
  取引を「止める」側へ ／ 数える側に `withTrashed()` ／ フラッシュのキー ／ モーダルの `@method('DELETE')` ／ 構造の 4 通り（未分類・実在しない名前・重複・空振り）と子の側（`UnitRentRevision` に `property()`、対照 `HsProperty::class`）／
  入力チェック 7 か所を 1 本ずつ（対照: 投資・契約の再現状態を別の物件の区画に替えると緑）
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright）: 関連データのある物件を詳細から削除 → 赤帯に文言・物件が残る ／ 関連データの無い物件は削除できる ／ コンソール 0 件
- **本番反映**（⚠ 利用者の明示承認のあと）: 13.x を取り込んでから FF マージ → 直前に本番の読み取り確認（削除済み物件が関連データを持たない）→ `./deploy.sh` → コンパイル済みビューの `php -l`。画面の確認は読み取りだけ（本番で削除はしない）

## 範囲外（案内だけ残す）

- `StructureTypeController` が削除済みの物件の構造も数える（削除済み物件だけが使う構造種別を消せない）
- 経営ダッシュボードの `aggregateTenantStats` は物件で絞らずに区画を数える（不変条件のもとでは影響なし）
- `TransactionController` にルートが無い（死んだコード）／`exists:properties` は部署を見ない
