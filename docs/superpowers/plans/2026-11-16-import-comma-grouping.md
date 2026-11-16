# CSV 取込の金額と面積のカンマの区切り — 実装計画

> 実行のしかた: この会話で私が Task 1 → 3 を順に行う（Native）。承認のあと、この計画を `docs/superpowers/plans/2026-11-16-import-comma-grouping.md` に写してコミットしてから始める。

**Goal:** 賃貸マンション・テナントの CSV 取込で、カンマの区切りが崩れた金額と面積（`5,5000`・`5,,000`・`5,50` など）を、黙って別の値で入れずにその行の誤りにする。

**Context:** 2 つの取込は金額と面積を「カンマを全部取り除いてから」読むので、`5,5000` が 55000、`5,50` が 550 として黙って入る（Bug #132・#133 の最後の点検で後回しにした件）。利用者が案 B（金額と面積の両方）を選び、短い設計書を承認した（2026-11-16「このとおり」）。

**Spec:** `docs/superpowers/specs/2026-11-16-import-comma-grouping-design.md`（コミット `3248d7a3`）

**作業場所:** worktree `nostalgic-jennings-a2186c`・枝 `tenant-import-column-limits`（13.x = origin/13.x = `32759892` から 1 コミット先）

## Global Constraints

- 決まり: カンマが無い値は今までどおり。カンマがある値は、整数の部分が `^\d{1,3}(?:,\d{3})+$` で、小数の部分（最初の `.` より後ろ）にカンマが無いときだけカンマを除いて読む。それ以外は崩れているとみなす
- 崩れた値は**今の形の誤りの文**でその行を誤りにする（新しい文は作らない）。金額は呼ぶ側の文（例「家賃「5,5000」は不正な値です」）、面積は「面積「…」は不正な値です」
- 決まりは `App\Support\CsvImportLimits` の 1 か所だけに置く（3 か所に正規表現を書かない）
- 検査の順・上限・ほかの文・総戸数／階数／階（`allowCommas: false`）は変えない
- DB・ルート・画面・新しいクラスの変更なし（本番への反映は `./deploy.sh` だけ）
- テストの流し方: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations [ファイル]`（worktree の cwd）。データプロバイダは `#[DataProvider]`
- コミットは Conventional Commits・日本語・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. 正しい区切りの小数（`1,234.56`）の面積 — 今までどおり入る（Task 2 のテストで固定）
2. 先頭の 0（`055,000`）— カンマ無しの `055000` と同じく読める（Task 1 の単体テストで固定）
3. 整数の部分が 4 桁以上で始まる区切り（`5555,000`）— 崩れているとみなす（Task 1 の単体テストで固定。`\d{1,3}` を緩める変異を止める）
4. 負の値のカンマ（`-1,000`）の面積 — 今と同じく「不正な値です」（Task 1 の単体テストで null を固定）
5. 登録済みの契約の行の金額が崩れていても、今までどおりスキップが先（検査の順を変えないので既存の `test_a_registered_contract_with_a_long_or_large_value_is_still_skipped` が守る。新しいテストは足さない）

---

### Task 1: 部品の決まりと金額

**Files:**
- Modify: `app/Support/CsvImportLimits.php`（新しい `withoutThousandsSeparators()`・`readUnsignedInt()` がそれを使う・docblock）
- Test: `tests/Unit/Support/CsvImportLimitsTest.php`
- Test: `tests/Feature/Admin/MansionImportColumnLimitsTest.php`（部屋の家賃）
- Test: `tests/Feature/Admin/TenantImportColumnLimitsTest.php`（区画の 5 つの金額）

**Interfaces:**
- Produces: `CsvImportLimits::withoutThousandsSeparators(string $raw): ?string` — カンマを除いた文字を返す。カンマが無ければ `$raw` をそのまま返す（数かどうかは見ない）。区切りが崩れていれば `null`

- [ ] **Step 1: 失敗するテストを書く**
  - 単体: 新しいデータプロバイダ `wellGrouped()`（`'55,000'→'55000'`・`'1,000,000'→'1000000'`・`'4,294,967,295'→'4294967295'`・`'1,234.56'→'1234.56'`・`'055,000'→'055000'`・カンマ無しの `'55000'→'55000'`・`'abc'→'abc'`・`''→''`）で `test_well_grouped_separators_are_removed` が `assertSame($expected, withoutThousandsSeparators($raw))`
  - 単体: `misplaced()`（`'5,5000'`・`'5,,000'`・`'5,50'`・`',5000'`・`'5000,'`・`'5555,000'`・`'1,234.5,6'`・`'-1,000'`）で `test_misplaced_separators_are_null`（`assertNull`）と `test_misplaced_commas_get_the_format_error`（`assertSame([null, 'FORMAT'], readUnsignedInt($raw, '家賃', 4294967295, 'FORMAT'))`）
  - 単体: 既存の `test_commas_are_refused_when_not_allowed` を `'2,500'` に変える（`allowCommas: false` は `[null, 'FORMAT']`・既定は `[2500, null]`）。`'2,5'→25` の行は消す（今は誤りになる）
  - 単体: クラスの docblock を「Bug #132・#133・2026-11-16 のカンマの区切り」に直す（後回しの小さな指摘の 1 つ）
  - 賃貸マンション: `roomNumberLimits()` の「家賃」の `$bad` に `'5,5000' => '家賃「5,5000」は不正な値です'` を足す
  - テナント: `test_unit_amounts_up_to_the_column_range_are_imported` に行 E（`'5,5000'`）を足し、`['row' => 5, 'message' => "{$header}「5,5000」は不正な値です"]` を期待に足す。確定後の 0 件の確かめに `'2E'` を足す。docblock の行の説明も直す
- [ ] **Step 2: 落ちることを確かめる** — 上の 3 ファイルを流す。期待: 単体は「undefined method withoutThousandsSeparators」と `misplaced` の readUnsignedInt が値を返す、Feature は rowErrors に `5,5000` の行が無く validCount が 2
- [ ] **Step 3: 実装** — `withoutThousandsSeparators()` は上の決まり（最初の `.` で分け、整数の部分を `/^\d{1,3}(?:,\d{3})+$/`、小数の部分に `,` が無いこと）。`readUnsignedInt()` は `$allowCommas` のとき部品が `null` を返したら `[null, $formatError]`。docblock の「2,5 が 25 で保存される」の説明を今の動きに合わせる
- [ ] **Step 4: 通ることを確かめる** — 同じ 3 ファイルが OK・警告なし
- [ ] **Step 5: コミット** — `fix: 取込の金額で、カンマの区切りが崩れた値をその行の誤りにする`

### Task 2: 2 つの面積

**Files:**
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（部屋の面積チェック・今 539〜551 行）
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（区画の面積チェック・今 512〜525 行）
- Test: `tests/Feature/Admin/MansionImportColumnLimitsTest.php`・`tests/Feature/Admin/TenantImportColumnLimitsTest.php`

**Interfaces:**
- Consumes: `CsvImportLimits::withoutThousandsSeparators(string $raw): ?string`（Task 1）

- [ ] **Step 1: 失敗するテストを書く**
  - 賃貸マンション: `test_area_with_thousands_separators_is_read_only_when_well_grouped` — 部屋 201 `'1,234.56'`・202 `'5,5000'`・203 `'1,234.5,6'`。rowErrors は `[['row' => 3, 'message' => '面積「5,5000」は不正な値です'], ['row' => 4, 'message' => '面積「1,234.5,6」は不正な値です']]`・validCount 1。確定後 201 の `area_sqm` が `'1234.56'`・202／203 は 0 件
  - テナント: 同名のテスト — 区画 B `'1,234.56'`・C `'1,50'`・D `'1,234.5,6'`（`1,50` は直す前 150 で入る値。`5,5000` は直す前も上限 9,999.99 で断られるので使わない）。rowErrors は C・D が「面積「…」は不正な値です」・validCount 1。確定後 2B の `area_tsubo` が `'1234.56'`・2C／2D は 0 件
- [ ] **Step 2: 落ちることを確かめる** — 2 本とも rowErrors が空（崩れた値が入る）で落ちる
- [ ] **Step 3: 実装** — 2 つの面積チェックの `str_replace(',', '', …)` を `CsvImportLimits::withoutThousandsSeparators(…)` にし、条件の先頭に `$val === null ||` を足す（文と上限は変えない）
- [ ] **Step 4: 通ることを確かめる** — 2 ファイルが OK・警告なし
- [ ] **Step 5: コミット** — `fix: 取込の面積で、カンマの区切りが崩れた値をその行の誤りにする`

### Task 3: 全件・変異・記録

**Files:**
- Create: `.claude/route-coverage-tools/mutate/specs.import-comma-grouping.py`（worktree の写し。git の管理外）
- Modify: `docs/RULES.md`（Bug #132 の行に「⚠ 2026-11-16 追記」・Bug #133 の行に同じ直しへの 1 文）
- Modify: `docs/BACKLOG.md`（新しい節・Bug #132 の節の後回しの行に「→ 2026-11-16 に直した」・完了状況の段落）

- [ ] **Step 1: 全件テスト** — Global Constraints の流し方（ファイル指定なし）。期待: `OK (4977 + 足した本数 tests, …)`・警告なし
- [ ] **Step 2: 変異の表を書いて流す**（先に期待を書く。Bug #44 の作法・`WT` をこの worktree に。対象のテスト: 2 つの ColumnLimits・`CsvImportLimitsTest`・賃貸マンションとテナントの取込のほかの Feature テスト）
  - C0 カナリア（面積の「不正な値です」の文を変える）
  - M1 部品が常にカンマを全部除く（直す前の形）／ M2 `\d{1,3}` → `\d+` ／ M3 `(?:,\d{3})+` → `(?:,\d+)+` ／ M4 小数の部分のカンマの確かめを外す ／ M5 `^` を外す ／ M6 `$` を外す
  - M7 `readUnsignedInt()` を `str_replace` に戻す ／ M8 賃貸マンションの面積を戻す ／ M9 テナントの面積を戻す ／ M10 面積の `$val === null ||` を外す（`is_numeric(null)` は false なので同じ文で断る＝等価の見込み。緑なら等価として記録）
  - 期待どおりでなければ、テストを足して流し直す
- [ ] **Step 3: 記録を書く** — RULES・BACKLOG（新しい節に: 何を・決まり・テスト・全件の数・変異の結果・範囲の外）。計画書の末尾に実測記録
- [ ] **Step 4: コミット** — `docs: 取込のカンマの区切りの直しを Bug #132・#133 と BACKLOG に記録する`

## 確かめ方（まとめ）

- 単体＋Feature のテスト（上）と全件テスト・変異テスト
- 画面は変えていないので、ビューの lint と実ブラウザの確認はしない（プレビューの描画は Feature テストが見る）
- 13.x への早送り・push・本番への反映は、利用者の指示があったときだけ（別々のコマンドで）
