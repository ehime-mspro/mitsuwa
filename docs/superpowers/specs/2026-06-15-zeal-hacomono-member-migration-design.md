# ZEAL 会員 移行インポート（hacomono形式CSV）設計書

- 作成日: 2026-06-15
- 対象: ZEAL（フィットネス事業）会員の別システム（hacomono系）からの一括移行
- 種別: 一回（ただし「第3弾」以降の再実行を想定し再利用可能に作る）

## 1. 背景と目的

ZEAL 会員データを別システム（hacomono系SaaS）のエクスポートCSVから本システム（`zeal_members` / `zeal_member_contracts`）へ移行する。

既存の「ZEAL会員CSVインポート」(`Admin\ZealMemberImportController`) は **16列の自社様式**専用で、今回の **77列のhacomono形式**は受け付けられない。さらに今回は退会済み会員も対象に含めるため、常に「在籍中」会員を作る既存インポーターでは表現できない。よって **専用の移行用 artisan コマンド** を新設する。既存インポーターは一切変更しない。

現状 `zeal_members` は 0 件（初回移行）。

## 2. ソースデータ（hacomono形式CSV）

- 列数 77、ヘッダー行あり、UTF-8。引用フィールド内に改行を含む（顧客内部カルテの移管メモ）ため **必ずCSVパーサで処理**（行分割禁止）。
- 添付された解析用ファイルは個人情報（氏名・カナ・性別・連絡先・生年月日）が空欄。**本番取り込みは個人情報入りの実データ版CSVを使用**（同一レイアウト前提）。
- 金融機関系カラム（口座番号等）はサンプルでは全て空。**移行では一切読まない・保存しない**。

### 使用するカラム（その他は無視）

| ソース列 | 用途 |
|---|---|
| ID（`CL...`） | トレース用にメモへ記録（移行元ID） |
| 状態 | 対象フィルタ（会員/停止中/ビジター） |
| 定期購入 | 課金有無の判定補助 |
| 名前 / 名前カナ | name / name_kana |
| 性別 | gender |
| 生年月日 | birthday |
| 電話番号 / メールアドレス / 郵便番号 / 住所 | phone / email / postal_code / address |
| 入会日 | joined_on / 契約 period_start |
| カスタム2 | **プラン判定の主**（表示プラン） |
| コース 名前 | プラン判定の従（カスタム2が空のとき） |
| 合計金額(2回目以降) | 月会費（実請求・税込）→ 税抜換算 |
| コース 合計金額(2回目以降) | 価格検証の参考（定価・税込） |
| 退会日 | 退会済み判定 / withdrew_on / 契約 period_end |
| 退会予定日 | 在籍だが解約予定 → メモ記録 |
| 変更後コース 名前 | 「休会プラン」への変更検出（休会判定） |
| 残チケット数 | チケット会員のメモ記録 |
| 紹介コード | メモ記録 |
| 顧客内部カルテ | 移管メモ（割引名）抽出 → メモ記録 |
| 店舗 名前 | store 判定 |

## 3. 取込先スキーマ（関連列・NULL制約）

### `zeal_members`（一部）
- `name` / `name_kana`（必須）
- `gender` NULL可（varchar、castは `ZealGender`）
- `birthday` NULL可 / `joined_on` **NOT NULL**
- `current_plan_id` **NULL可**（プランなし会員を許容）
- `withdrew_on` NULL可 / `withdraw_reason` NULL可 / `withdraw_note` NULL可
- `store_id` / `trainer_id`（NULL可）/ `acquisition_source`（NULL可）/ `purpose`（NULL可）/ `memo`
- `created_by` **NOT NULL** / `updated_by`

### `zeal_member_contracts`（SCD Type-2）
- `member_id` / `plan_id` **NOT NULL** ← プランなし会員は契約行を作れない
- `period_start` NOT NULL / `period_end` NULL可（NULL=現行契約）
- `applied_price_excl` NOT NULL（int unsigned）
- `is_campaign_applied` / `tax_rate_at_contract` / `change_reason`（`ZealContractChangeReason`）/ `note`(varchar200) / `created_by` NOT NULL

## 4. スコープ

- **対象**: 状態 ∈ {会員, 停止中} … 35件
- **除外**: 状態 = ビジター … 5件（スタッフ用アカウント3・体験/チケットの来訪者2）

## 5. マッピング仕様

### 5.1 会員フィールド
| 取込先 | 値 |
|---|---|
| name / name_kana | 名前 / 名前カナ |
| gender | 性別（男性→male / 女性→female / その他→other）。空欄は警告のうえ null 取込 |
| birthday | 生年月日（`YYYY/M/D`→`YYYY-MM-DD`正規化、空はnull） |
| phone / email / postal_code / address | 同名ソース列（空はnull） |
| joined_on | 入会日 |
| current_plan_id | §5.2 で解決（チケット会員のみ null） |
| store_id | §5.3 で解決 |
| trainer_id / acquisition_source / purpose | null（ソースに対応データなし） |
| memo | §5.6 |
| created_by / updated_by | §5.7 |

### 5.2 プラン解決（明示エイリアス表）
判定元は **カスタム2 を主**、空なら **コース 名前** を従とする（停止中はコース名前が空のためカスタム2必須、休会はコース名前が「休会プラン」になるためカスタム2優先で実プランを取る）。

| ソース表記 | 既存プラン名 | 税抜定価 |
|---|---|---|
| （新）パーソナル＆セミパーソナル通い放題（2枠） | パーソナル&セミパーソナル通い放題（2枠） | 24,000 |
| 【松山市駅前】パーソナル&セミパーソナル通い放題(1枠) | パーソナル&セミパーソナル通い放題（1枠） | 18,000 |
| （新）パーソナル＆セミパーソナル月4回 | パーソナル&セミパーソナル月4回 | 13,000 |
| パーソナル&セミパーソナル月4回（松山市駅前） | パーソナル&セミパーソナル月4回 | 13,000 |
| （新）セミパーソナル通い放題 | セミパーソナル通い放題 | 9,800 |
| セミパーソナル通い放題（松山市駅前） | セミパーソナル通い放題 | 9,800 |
| セミパーソナル通い放題（松山市駅前）（1年契約） | セミパーソナル通い放題 | 9,800 |
| ペアプラン | ペアプラン | 20,700 |

- 上表に一致しない値（`チケット会員` 等）は **未対応** として扱う（§5.5 のチケット会員処理 or プレビューでエラー表示）。`休会プラン`/`スタッフ用アカウント`/空 は「プランではないラベル」として除外語に登録。
- エイリアスは前後空白を `trim` してから完全一致で照合（`【松山市駅前】…(1枠)` は末尾に空白あり）。

### 5.3 店舗解決
- 店舗名前 `ZEAL BOXING FITNESS 松山市駅前店` → 既存 `松山市駅前店`（エイリアス）。
- 不一致・空欄時は唯一の有効店舗（`松山市駅前店`）にフォールバック。

### 5.4 月会費（税抜）算出
- 原則: `applied_price_excl = round(合計金額(2回目以降) ÷ (1 + 税率/100))`（税率は `Settings::taxRate()`、現状10）。
- 退会済み・定期購入OFF（実請求0）の在籍 → **プラン定価（税抜）** を採用（§5.5）。
- 休会 → 実際の休会費（`合計金額(2回目) ÷ 1.1` = 1,000）を採用（§5.5）。
- **リスクと対策**: 一部プラン variant（例「セミパーソナル通い放題（松山市駅前）」）は元データの税込/税抜の持ち方が他と異なる可能性がある。一律 ÷1.1 が過小/過大になり得るため、**ドライランで「元の合計金額 / コース定価 / 計算後税抜 / プラン定価」を並記**し、コミット前に目視検証する。系統的なズレが出た場合は variant 別ルールを追加する。

### 5.5 在籍区分の判定と処理
判定順（上から優先）:

1. **退会済み**（状態=停止中 **または** 退会日あり）: 4件
   - `withdrew_on = 退会日`、`withdraw_reason = null`、`withdraw_note = "別システムより移管（退会済み）"`
   - 契約: `period_end = 退会日`、`applied_price_excl = プラン定価(税抜)`、`change_reason = new_join`
   - 注: 1件は状態=会員だが退会日が過去（`2026/4/1`）。退会済みとして処理。
2. **休会**（コース名前 or 変更後コース名前 = `休会プラン`）: 1件（CL97195476）
   - プランはカスタム2 由来（セミパーソナル通い放題）。在籍（契約open）。
   - `applied_price_excl = 1,000`（実休会費 1,100 ÷ 1.1）。`memo` に「移管時点で休会中」。
3. **チケット会員 / 未対応プランの在籍**: 1件（CL64334328）
   - `current_plan_id = null`、**契約行は作成しない**（plan_id 必須のため）。
   - `memo` に「移管時チケット会員（残N枚・定期購入なし）」。プレビューで要確認フラグ。
4. **定期購入OFF・実請求0の在籍**（プランは判明）: 2件
   - 在籍（契約open）。`applied_price_excl = プラン定価(税抜)`。`memo` に「定期購入なし（移管時）／チケット残N」。
5. **在籍（通常）**: 残り
   - 契約open。`applied_price_excl = round(合計金額(2回目) ÷ 1.1)`。
   - 退会予定日あり（3件）は在籍のまま `memo` に「退会予定日 YYYY/MM/DD」。

全在籍契約: `period_end = null`、`is_campaign_applied = false`、`tax_rate_at_contract = 現税率`、`change_reason = new_join`。

### 5.6 メモ生成（トレーサビリティ）
`memo` に以下を改行区切りで自動付与（存在するもののみ）:
- `移行元ID: CL........`
- `割引名: ...`（顧客内部カルテの移管メモから抽出）
- `退会予定日: YYYY/MM/DD`
- `紹介コード: ...`
- 区分補足（休会中 / チケット会員残N / 定期購入なし 等）

### 5.7 登録者（created_by / updated_by）
- CLI実行のため `auth()->id()` は無い。`--actor=<email>`（既定 `m-saiki@mitsuwat.co.jp`）で `users` を引いて id を解決。見つからなければ即エラーで中断。

## 6. バリデーション / 警告（プレビューに集約）
- **エラー（取込しない）**: ヘッダー不一致、入会日が空（必須）、プラン未解決かつチケット会員ルールにも該当しない、店舗解決不可。
- **警告（取込はする）**: 性別が空（null取込）、休会、チケット会員、定期購入OFF・0円在籍、退会日が状態と不整合、価格が定価と乖離（要目視）。

## 7. コマンド設計
- 署名: `php artisan zeal:import-members {path} {--dry-run} {--commit} {--actor=m-saiki@mitsuwat.co.jp}`
  - `--dry-run`（既定動作）: 解析・判定のみ。**プレビュー表**＋サマリ（取込予定/警告/エラー/スキップ件数）を表示。DBは触らない。
  - `--commit`: `DB::transaction` で `ZealMember` ＋（プランありは）`ZealMemberContract` を作成。
  - 安全装置: `--commit` でもエラー行があれば中断（`--force` 無しでは投入しない）。
- **冪等性**: 既存に同一 `name`＋`joined_on` があればスキップ（第3弾再実行・誤再実行に耐える）。現状0件のため初回は全件新規。
- プレビュー表の列: 行 / 移行元ID / 氏名 / 状態 / 採用プラン / 元合計金額 / コース定価 / 計算後税抜 / 区分 / 退会(予定)日 / 警告。
- CSV読込は既存の文字コード自動判定・BOM除去の考え方を踏襲（UTF-8前提だがSJISも許容）。

## 8. アーキテクチャ（隔離・テスト容易性）
既存 `ZealMemberImportController` は変更しない。新規ファイルのみ:

- `app/Support/Zeal/HacomonoMemberMapper.php` — **純粋ロジック**。入力: CSV行(array) ＋ プラン定価マップ ＋ 店舗マップ ＋ 税率。出力: `MappedMember`（会員フィールド / 解決プラン / 月会費税抜 / 区分 / 契約要否 / 警告・エラー配列）。DB非依存でユニットテスト可能。
- `app/Support/Zeal/MappedMember.php` — 結果DTO。
- `app/Console/Commands/ZealImportMembersCommand.php` — CSV読込、マスタ取得、Mapper適用、プレビュー出力、トランザクション投入。
- プラン/除外語のエイリアス表は Mapper 内の定数（変更時に一目で分かる位置）。

## 9. テスト方針
- main repo で `composer install`（dev込）→ `vendor/bin/phpunit` →（後片付け）`composer install --no-dev`（worktreeにvendor無し）。
- `HacomonoMemberMapper` のユニットテスト:
  - 各プラン variant → 正しい既存プランに解決
  - 税抜換算（÷1.1, round）
  - 区分判定: 在籍 / 退会済み（停止中・過去退会日）/ 退会予定 / 休会 / チケット会員 / 定期購入OFF0円
  - 店舗エイリアス / 性別空→警告null / 入会日空→エラー
- フィクスチャは添付サンプルCSV（PIIなしでも区分・プラン・価格判定は検証可能）から数行を抜粋。

## 10. 本番反映手順（ロールアウト）
1. worktree で実装＋テスト緑。
2. **ローカルDBでリハーサル**: `--dry-run` → 目視 → `--commit` → ZEAL会員一覧UIで確認 → ローカルは `zeal_members`/`zeal_member_contracts` を truncate して0件に戻す。
3. `/commit` → main repo で 13.x へ FF-merge → **main repo で `composer dump-autoload`**（新規クラスのため）→ `./deploy.sh`。
4. 実データCSVを本番へ安全に転送（`storage/app/` 配下の一時パス。rsync/scp）。
5. 本番で `ssh ... '/bin/sh -c "cd <path> && php artisan zeal:import-members storage/app/import.csv --dry-run"'` → プレビューをユーザーが確認。
6. 承認後 `--commit` 実行。
7. **本番のCSVを削除**（個人情報の後片付け）。
8. 本番ZEAL会員一覧で件数・内容を確認。

## 11. 非対象 / 将来
- ペアプランの親子（`pair_parent_member_id`）の紐付けは行わない（移行後に手動）。
- チケット残数・ポイント・決済情報・口座情報は移行しない（メモ記録のみ）。
- variant別の税抜ルールはドライラン検証の結果が良好なら追加しない（YAGNI）。

## 12. 確定した意思決定ログ
1. 取込範囲 = 会員＋停止中の35件（ビジター除外）。
2. 本番は氏名・カナ・性別入りの実データ版CSVを使用（添付は様式確認用）。
3. 月会費 = 各会員の実請求額（割引後）を税抜換算。
4. 取込方式 = 専用の移行 artisan コマンド（dry-run→commit）。既存画面は不変更。
5. 退会済み4件の月会費 = プラン定価（税抜）。
6. 休会1件の月会費 = 実際の休会費1,000円＋メモ「休会中」。
7. （既定・要レビュー）チケット会員1件 = 会員として取込・契約なし・メモにチケット残。
8. （既定・要レビュー）定期購入OFF0円の在籍2件 = プラン定価（税抜）。
