# ZEAL 会員 CSVインポート 新フォーマット対応 設計書

- 作成日: 2026-06-24
- 対象: ZEAL（フィットネス事業）会員管理
- 種別: 既存機能の改修（Web取込のCSVフォーマット切替）

## 1. 背景・目的

ZEAL の会員管理元が **別システムへ移行**し、エクスポートされる CSV のフォーマットが変わった
（hacomono は現在不使用）。Web UI「ZEAL 会員 CSVインポート」が受け付ける形式を、
**新システムが出力する 77 列形式**へ切り替える。

サンプル: `clients_csv_JT9011396252.csv`（個人情報は共有用に削除済 = 本番には氏名等が入る前提）。

## 2. 現状の整理

ZEAL 会員の取込は現在 2 系統ある。

| 系統 | 形式 | 実装 | 状態 |
|---|---|---|---|
| Web UI「会員CSVインポート」 | 手作り **16 列**テンプレート | `Admin\ZealMemberImportController` + `admin/zeal-member-import/{index,preview}.blade.php` | 日常運用（この形式専用） |
| 移行用 CLI コマンド | **77 列**エクスポート | `zeal:import-members` + `App\Support\Zeal\{HacomonoCsvReader,HacomonoMemberMapper,MappedMember}` | 2026-06-15 の一回限り移行（35件）で使用済 |

- ZEAL 会員は**手動の新規登録フォームが無く**、追加手段は CSV インポートのみ（`routes/web.php:1347` 付近のコメント）。
- 既存の `HacomonoMemberMapper` は 24 件の PHPUnit でカバーされ、本番移行で実証済み。

### 重要な発見

添付サンプルを `fgetcsv` で解析した結果、**新システムの 77 列形式は移行で使った形式と同一構造**だった。

- 列名・並びが一致（77 列）。`HacomonoCsvReader`/`HacomonoMemberMapper` がそのまま解釈可能。
- データ 42 行 → 状態別: **会員 33 / 停止中 3 / ビジター 6**。
- プラン名（`カスタム2`・`コース 名前`）は**全て既存 `PLAN_ALIAS` でカバー済み**。新規別名の追加は不要。
- 休会・次回休会予定・退会予定・税込/税抜混在の月会費 — いずれも既存ロジックが処理可能。

→ **ゼロから作らず、実証済みの Reader+Mapper を Web 取込へ流用する**のが最小リスク・最小工数。

## 3. 決定事項（確定）

| # | 論点 | 決定 |
|---|---|---|
| D1 | 対応方針 | **hacomono(新)形式へ置換**。16 列テンプレートは廃止し、Web 取込を新形式専用に統一 |
| D2 | 取込対象 | **名簿まるごと**。在籍/退会済/休会/チケット/定期OFF を 5 区分判定。ビジター・スタッフ用アカウントは除外 |
| D3 | 重複判定キー | **氏名 + 入会日のまま**（スキーマ変更なし）。会員ID(CLxxxx)ベースの堅牢化は将来課題 |
| D4 | システム前提 | hacomono は不使用。**ユーザー向け文言**を実態（別システム/日常運用）に寄せる。内部クラス名（`Hacomono*`）の一般化は将来課題 |
| D5 | 個人情報 | 本番エクスポートには氏名・カナ等が含まれる前提（サンプルは削除済）。`氏名` 必須・氏名ベース重複判定は機能する |

## 4. 新フォーマット仕様（77 列のうち取込が参照する列）

`HacomonoMemberMapper` が読む列のみ抜粋（列番号はサンプル基準）。

| 用途 | 列名 | 備考 |
|---|---|---|
| 元会員ID | `ID` (1) | 例 `CL23326867`。memo に「移行元ID」として記録 |
| 在籍状態 | `状態` (2) | `会員`/`停止中` のみ取込対象。`ビジター` は除外 |
| 定期購入 | `定期購入` (3) | `TRUE`/`FALSE`。OFF かつ実請求 0 → 定期OFF 区分 |
| 連絡先・属性 | `メールアドレス`(5) `名前`(6) `名前カナ`(7) `電話番号`(9) `郵便番号`(10) `住所`(11) `性別`(13) `生年月日`(14) | `名前` 必須。`性別` 空は警告（null取込） |
| プラン（主） | `カスタム2` (17) | 第一候補。`PLAN_ALIAS` で自社プラン名へ解決 |
| 入会日 | `入会日` (31) | 必須。`period_start` の基準 |
| 退会 | `退会予定日`(35) `退会日`(36) | 退会日ありで退会済・契約クローズ |
| 紹介 | `紹介コード`(39) | memo に転記 |
| 内部メモ | `顧客内部カルテ`(41) | 「割引名: …」を正規表現抽出して memo へ |
| 残チケット | `残チケット数`(46) | チケット/定期OFF 区分の memo に記録 |
| 月会費(実) | `合計金額(2回目以降)`(52) | 在籍/休会の `applied_price_excl` 算出元（税込/税抜判別） |
| 店舗 | `店舗 名前`(65) | `STORE_ALIAS` で自社店舗名へ。未解決は既定店舗 |
| プラン（従） | `コース 名前`(69) | `カスタム2` 空/非プランのときの第二候補 |
| コース定価 | `コース 合計金額(2回目以降)`(72) | プレビュー表示用の参考定価 |
| 次回コース | `変更後コース 名前`(74) | `休会プラン` なら「次回休会予定」警告（在籍として取込） |

### 区分判定（優先順、既存 `HacomonoMemberMapper::map` 準拠）

1. **退会済 (withdrawn)**: `状態=停止中` または `退会日` あり → プラン定価(税抜)・`period_end=退会日`・退会メモ。
2. **休会 (dormant)**: `コース 名前 = 休会プラン` → 実休会費(税抜)。
3. **チケット (ticket)**: プラン未解決（チケット会員等）→ 会員のみ作成・**契約なし**。
4. **定期OFF (inactive_zero)**: `定期購入=FALSE` かつ実請求 0 → プラン定価(税抜)。
5. **在籍 (active)**: 上記以外 → 実請求(税抜)。

### 除外・スキップ

- **除外**: `状態` が `会員`/`停止中` 以外（ビジター）。`コース 名前` が `スタッフ用アカウント`/`チケット会員`/`休会プラン` は NON_PLAN として扱い、プラン解決時に読み飛ばす。
- **重複スキップ**: 氏名 + 入会日が既存会員と一致。
- **エラー（取込しない）**: 氏名空・入会日不正・退会者/休会者のプラン未解決・定価不明 等。

## 5. 実装設計

方針は **D1〜D5 を満たす最小差分**。コアロジック（Mapper/区分判定/プラン別名/税抜判別）は既存資産を流用し、
**触るのは Web 層（コントローラ + 2 Blade + ルート）とテスト**に限定する。

### 5.1 触るファイル

| ファイル | 変更内容 |
|---|---|
| `app/Support/Zeal/HacomonoCsvReader.php` | `readContent(string $content): array` を切り出し、`read($path)` はそれに委譲（後方互換・既存テスト不変）。Web 側が「文字列」を渡せるようにするため |
| `app/Http/Controllers/Admin/ZealMemberImportController.php` | preview/execute を Reader+Mapper 呼び出しへ全面置換。16 列用の `columnMap`/`genderMap`/`acquisitionMap`/`purposeMap`/`normalizeDate`/`toCsvLine` と `template()` を撤去。`loadCsv` の文字コード判定・base64 復元は残し、`explode("\n")` をやめ `readContent()` 経由に（**引用フィールド内改行に対応＝顧客内部カルテの複数行に必須**） |
| `resources/views/admin/zeal-member-import/index.blade.php` | カラム仕様を新フォーマット説明へ刷新（区分・除外ルール・重複ルール）。サンプルDLボタンは撤去（新形式は手作りしないため） |
| `resources/views/admin/zeal-member-import/preview.blade.php` | 区分(在籍/退会済/休会/チケット/定期OFF)・警告・除外・重複・エラーを一覧表示。CLI の表（`ZealImportMembersCommand::renderPreview`）の HTML 版。**inline style 厳守**（Vite ビルド済 Tailwind 制約・RULES.md） |
| `routes/web.php` | `zeal/member-import/template` ルートを削除 |
| `tests/Feature/...` | コントローラの preview/execute feature テストを新規追加（後述） |

**触らない**: `HacomonoMemberMapper`・`MappedMember`・`ZealImportMembersCommand`・既存の Mapper/Reader テスト（`readContent` 追加に伴うリーダーテスト 1 件のみ追記）。

### 5.2 命名・文言の一般化（D4）

- **クラスのリネームは本改修では行わない**（24 テスト＋コマンドへ波及し churn 過大。コアは無変更で温存）。`Hacomono*` は内部の歴史的名称として許容。
- **ユーザーが目にする文言のみ中立化**: Blade 上の説明文に「hacomono」「移行」を出さず、「会員管理システムのエクスポートCSV」等と表現。
- memo の生成文言（`移行元ID:` / `別システムより移管` 等）は Mapper 内のため**今回は据え置き**（実態として外部システム由来であり不自然ではない）。将来クラス一般化と併せて見直す。

### 5.3 取込フロー（execute）

`ZealImportMembersCommand::commit` と同等の永続化を Web トランザクション内で行う。

```
rows = ReaderでCSVをパース
for each row:
    if !isInScope(row): 除外カウント; continue          // ビジター
    m = mapper.map(row)
    if m.hasErrors(): エラーカウント; continue            // 取込しない（プレビューで明示済）
    if 氏名+入会日が既存: スキップカウント; continue
    ZealMember::create(m.memberAttributes + created_by/updated_by = auth id)
    if m.contractAttributes !== null:
        ZealMemberContract::create(+ member_id, is_campaign_applied=false,
                                   tax_rate_at_contract=現税率, created_by=auth id)
    登録カウント++
完了メッセージ: 登録 N / スキップ M / エラー K / 除外 L
```

- `created_by`/`updated_by` は **CLI の固定 actor ではなくログインユーザー**（`auth()->id()`）。
- 税率は `Settings::taxRate()`（不在時 10% フォールバック）。
- **エラー行は取込まずスキップ**（既存 Web 16 列版の挙動を踏襲）。プレビューでエラーを明示し、ユーザーが元CSVを直して再アップロードできるようにする。CLI の「エラーあれば全件中断」とは方針を変える（Web は部分取込許容）。

### 5.4 プレビュー画面（preview.blade.php）

- 取込予定テーブル: 元ID / 氏名 / 状態 / **区分バッジ** / プラン / 月会費(税抜) / 退会(予定)日 / 警告。
- 区分は文言ラベル（在籍/退会済/休会/チケット/定期OFF）。バッジ色は inline style。
- 別枠で「除外（ビジター等）」「重複スキップ」「エラー（取込しない）」を件数＋明細表示。
- 確定ボタンで execute（既存どおり base64 で CSV を持ち回り再パース）。

## 6. テスト計画

- **Reader**: `readContent()` 追加に対し、文字列入力で `read($path)` と同結果になる単体テスト 1 件追加。既存リーダー/マッパーテストは不変で green を維持。
- **Controller（Feature, 新規）**: 氏名を埋めた**新フォーマットのフィクスチャ CSV**（サンプル相当・全 5 区分＋ビジター＋重複を含む小規模データ）を用意し、
  - `preview`: 在籍/退会済/休会/チケット/定期OFF/除外/重複/エラー の件数とプレビュー表示を検証。
  - `execute`: `ZealMember` と `ZealMemberContract`（チケットは契約なし、退会済は `period_end` あり）の生成件数・主要カラムを検証。重複行がスキップされること。
  - プラン/店舗マスタはテスト内で seed。
- 期待スコープ感（サンプル基準）: 77 列・42 行 → 取込対象(会員+停止中) 36 / ビジター除外 6。

## 7. デプロイ・検証手順（本番反映）

1. worktree で実装 → `/commit`。
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only <branch>`。
3. **Blade 検証（Bug #26 対策）**: `php artisan view:cache` 成功だけで判断せず、コンパイル済みビューを必ず lint:
   ```
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
4. 実データ（または本物の新フォーマット CSV）で preview をローカル検証（空ローカルで素通りする本番500を避ける。Bug #22/#25/#26 同型）。
5. `./deploy.sh`（rsync + 本番 config/route/view:cache 再生成）。
6. origin/13.x への push はユーザー明示指示時のみ。
7. （任意）Playwright で本番動作確認。

## 8. スコープ外（今回やらないこと）

- 会員ID(CLxxxx)ベースの重複判定・`source_member_id` 列追加（D3 で「最小」を選択）。
- プラン変更・退会の**継続同期**（本取込は新規追加のみ。既存会員の変更は Web の changePlan/withdraw で手動運用）。
- `Hacomono*` クラスの全面リネーム（将来の整理課題）。
- 移行コマンド `zeal:import-members` の削除（歴史的資産として温存。Web 置換後は実質未使用）。

## 9. リスク・留意点

- **フォーマット差異**: サンプルは個人情報削除済のため、本番の実列構成が微妙に異なる可能性。実 CSV で preview を一度通して列マッピングを確認する。
- **会員ID連続性**: 新システムの `ID`(CLxxxx) が 6月移行済み 35 件の memo「移行元ID」と一致するか未確認。氏名+入会日重複判定なので取込自体は問題ないが、将来 ID キー化する際に要確認。
- **氏名必須前提（D5）**: 新システムが氏名を CSV に出力しない運用なら、`氏名` 必須・氏名重複判定が破綻する。実 CSV で要確認。
- **税込/税抜判別**: `toMonthlyExcl` は「金額×100 が (100+税率) で割り切れる＝税込」と推定するヒューリスティック。新システムの金額表記が移行時と同傾向である前提。
```
