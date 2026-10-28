# 手で組んだ配列の送信で 500 になる件（戻った画面・保存）を入力チェックの 1 か所で直す — 実装計画

承認: 2026-10-28（利用者。決めること ①②③ は推薦どおり）

## Context

- 本人が手で組んだ送信（`x[]=1`・配列のつもりの欄に `x`）で、2 通りの 500 が出る
  1. **戻った画面の 500**: 入力チェックで断ると Laravel の `Handler::invalid()` が送られた入力をそのまま控え（`withInput`）、画面が `old()` を文字のつもりで描く（`htmlspecialchars()` の TypeError・`Array to string conversion`・逆向きは `array_values()`／`foreach()`）
  2. **保存の 500**: `exists` / `required` / `max` だけの欄は配列を素通りさせる
- 狙い: 形の判定を 1 か所（`App\Support\ShapeCheckingValidator`）に置き、入力チェックがその判定で形の合わない欄を断り（2）、断ったときの控えからその欄を外す（1）。画面は直さない
- 作業場所: `.claude/worktrees/route-coverage`・枝 `array-input-500`（2353d14c から）。DB 変更なし。新しい PHP クラス 1 つ

## 決めたこと

| # | 決めること | 決めた形 |
|---|---|---|
| ① | 足す誤りの文 | 「:attributeの形が正しくありません。画面を開き直して、もう一度入力してください。」 |
| ② | 文の置き場所 | `ShapeCheckingValidator::MESSAGE`（`lang/ja/validation.php` はフレームワークのキーと 1 対 1 のまま） |
| ③ | 送り直しの測定 | 作る（git の管理外。`.claude/route-coverage-tools/array-input/probe-replay.php`） |

## Task 0 — 直す前の測定（2026-10-28）

### 送り直しの測定（`probe-replay.php`・集計 `agg-replay.php`）

全件テストの中の POST / PUT / PATCH を、元の応答のあとで形を変えて送り直した（A: 文字の値をすべて 1 つの配列に／B: 一番上の配列をすべて `x` に。DB とセッションとログインは元に戻す）。全件 4495 本・送り直し 1958 回。元のテストの赤 15 本は送り直しの副作用（1 回限りの鍵・ログインの制限など）で、測定の雑音。

| 数えたもの | 直す前 |
|---|---|
| 保存の 500（送り直しの応答が 500） | **11 経路** |
| 戻った画面の 500（302 の行き先を開くと 500） | **79 経路**（うち逆向き B は 4: 仕入れ案件・分譲地の登録の `costs=x`・投資の登録と編集の `details=x`） |

保存の 500 の 11 経路と落ちる場所:

| 経路 | 欄 | 落ちる場所 | 直し方 |
|---|---|---|---|
| `admin.users.president` | `president_user_id[]` | 入力チェックを通って保存（知らせを作れない） | 判定で断る |
| `realestate.contracts.store` | `procurement_id[]`・`buyer_id[]` | 入力チェックを通って保存 | 判定で断る |
| 〃 | `contract_type[]` | **入力チェックの前**（`ReContractType::tryFrom($request->contract_type)`） | コントローラで文字のときだけ読む |
| `housing/realestate.customers.store/update`（4） | `birth_date[]`・`birth_era[]` | **入力チェックの中の自作の規則**（`birthEraMatches()` が request を直接読む） | 判定を**ほかの規則より先に**見る（下） |
| `zeal.simulations.sheet-urls.update` | `sales_sheet_url[]` など | **入力チェックの中の自作の規則**（`parse_url($value)`） | 同上 |
| `zeal.simulations.sheet-import.preview` | `year_month[]` | **入力チェックの前**（`(string) $request->input('year_month')`） | コントローラで文字のときだけ読む |
| `approvals.admin.organization.departments.store/update` | `code[]` | **入力チェックの前**（`(string) $request->input('code')`）・決裁のコントローラ | 直さない（「社内決裁申請」の会話に知らせる） |
| `approvals.admin.organization.mailDomains.store` | `domain[]` | **入力チェックの前**（`ApprovalMailDomain::normalize()`）・決裁のコントローラ | 同上 |

### 計画からの変更（測定で分かったこと）

- **判定はほかの規則より先に見る**。形が合わない欄があれば、ほかの欄の規則は見ずに断る（自作の規則が配列を読んで落ちるため。`birthEraMatches()`・Sheet の URL の `parse_url()`）
  - 形が合わない欄の文言は、その欄の**型の規則**（`string` / `integer` / `numeric` / `decimal` / `boolean` / `date` / `date_format` / `email` / `url` / `in` / `regex` / `array` / `list`。配列を受けても落ちない組み込みの規則だけ）があれば、その規則の今の文（例「付け替え先は整数で入力してください。」）。無ければ ① の文
  - 形の合わない欄が 1 つでもある送信では、ほかの欄の誤りは出ない（直して送り直すと出る）。手で組んだ送信だけの場面
- 入力チェックの前に input を読んで落ちるコントローラは、判定では止まらない → 基幹の 2 か所（不動産の契約の登録・ZEAL の Sheet の取込のプレビュー）は個別に直す。決裁の 2 か所は直さない

### 規則の無い欄（静的に数えた: 画面が `old()` で描くのに、どの入力チェックにも規則が無いキーは 10）

| キー | 測定 | 直し方 |
|---|---|---|
| `survey_memo` | 戻った画面の 500（アンケートの登録・編集） | 規則と和名を足す |
| `return_to` | 戻った画面の 500（賃料改定） | `nullable|in:unit,contract` と和名 |
| `apply_from_month` | （ほかの欄の誤りに隠れて送り直しでは出なかった。`(string)` で読むので配列で 500 の見込み） | 手作りの確認を入力チェックの規則に置き換える |
| `birth_year` `birth_month` `birth_day` | （同上） | 形だけの規則と和名 |
| `keep_adding` `remember` | — | 直さない（`old()` を真偽にしか使わない） |
| `survey` `survey.{$qId}` | — | 直さない（`SurveyAnswers` が形を見る） |

## Task 1〜7（実測記録）

承認した計画（`~/.claude/plans/dazzling-noodling-bird.md`）に沿って進めた。コミット（3cca6503 の次から）:

| コミット | 中身 |
|---|---|
| 48deef93 | 判定のクラス `ShapeCheckingValidator` と Unit テスト |
| 8f2acb8e | resolver・render・不動産の契約の種別と ZEAL の Sheet の対象月を文字のときだけ読む・各部署の `ArrayInputTest` |
| 434df762 | ログインの制限の応答で配列のログイン ID を控えない・ログインのテストを戻った画面まで見る |
| 4b0fb9e3 | 規則の無い欄に規則（アンケートの備考・戻り先・適用開始月・生年月日の年月日）・走査 `OldInputRuleCoverageTest` |
| 797b5668 | 守り手の無かった枝のテスト（request にしか無い行・アンケートの更新・Sheet の反映） |
| fc951a6c | 入力チェックを 1 回にまとめる（原価・住宅の買主・適用開始月）・種別で使わない欄に exclude・駐車場契約の物件・買主の備考 |
| a315f8fe | 親の欄が exclude なら子の欄の形でも断らない |

- **赤の確かめ**: 足したテストは、どれも直す前のコードで狙った理由（500・TypeError・`htmlspecialchars()`）の赤を確かめてから直した（例外: 投資の行が文字の場合は Laravel が「必須です」で断っていて 500 にならなかったので、そのテストは外した）。
  ⚠ 規則の無い欄の赤を確かめるとき、コミットしていないコントローラの変更を `git checkout --` で戻してしまい、当て直した（以降は先にコミットしてから戻した。Bug #44）
- **直したあとの送り直しの測定**（全部の欄を一度に・経路ごとに 1 つずつ）: 1 回目（8f2acb8e〜797b5668）は保存の 500 = 3（決裁）・戻った画面の 500 = 13 → 残った基幹の 7 経路の原因が「入力チェックが 2 回」「種別で規則が変わる」「規則の無い欄（`memo`・`property_id`）」と分かり fc951a6c で直した → 2 回目は **保存の 500 = 3・戻った画面の 500 = 1（どれも決裁）・基幹は 0**
  ⚠ 走査 `OldInputRuleCoverageTest` は買主の `memo` を見落としていた（別の画面の `memo` に規則がある＝死角）。測定で見つけた
- **変異**（`.claude/route-coverage-tools/mutate/specs.array-input.py`・結果 `results-array-input.txt`）: 1 回目 39 通りのうち 34 通りが期待どおり。違った 5 つ: V06・V09・V11 は表が狭かった（exclude の欄・駐車場・不動産の契約のテストも落ちる）・K06 は理由の書き違い（更新は保存の失敗を受け止めて控えずに戻す）・**K12 は判定の穴**（親の欄が exclude でも子の欄の形を断っていた）→ a315f8fe で直し V16 を足して、最後のコードで **40 通りすべて期待どおり**（落ちたテストの組と理由まで）
- **全件** 4495 → **4560 tests green**（PHP 8.3.35・3 分・メモリのピーク 165.5 MB）・コンパイル済みビュー 315 本 / INVALID 0（ビューは変えていない）
- **実ブラウザ**（使い捨ての環境・Playwright）: ログインで `login_id[]` を送ると、戻ったログイン画面に「ログインIDは文字列で入力してください。」が出て入力欄は空・テナント契約の登録で `unit_id[]` を送ると、戻った画面に「区画の形が正しくありません。画面を開き直して、もう一度入力してください。」が出て、備考の入力は残る。コンソールのエラー 0
- **決裁の会話**: 2026-10-28 の時点で動いていなかったので、起こさずに記録だけ残した（決裁の残り 4 経路は BACKLOG の節）
