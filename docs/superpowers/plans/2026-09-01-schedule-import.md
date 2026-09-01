# ANDPAD 工程表取込 実装プラン

**作成日**: 2026-09-01
**設計書（正本）**: `docs/superpowers/specs/2026-09-01-schedule-import-design.md`
**worktree**: `.claude/worktrees/andpad-import`（branch `andpad-import`、`021a1530` から）
**前提**: 工程表機能（`schedule_steps`）は 2026-09-01 に本番稼働済み・実データ 0 件

---

## ⚠ 2026-09-01 追記 — アプリから「ANDPAD」の名称を外した（commit `78108891`）

利用者の指示は「**ANDPAD は書き出し元というだけなので、システム上に文言があれば省いて**」。
このプランの本文（Task 4〜8 の画面文言・クラス名）は**改名前**の姿なので、現物と読み合わせるときは
下の対応表で引き直すこと。**プランと設計書に名称を残しているのは「なぜサーバ側で xlsx を解析して
いるのか」の記録として要るため**で、`app` / `resources` / `routes` / `database` / `tests` には **0 件**。

| プラン本文 | 現物 |
|---|---|
| 画面「ANDPAD 工程表の取込」 | **「工程表の取込」** |
| カードのボタン「ANDPAD 取込」 | **「工程表を取り込む」** |
| 「ANDPAD の書き出しファイル」 | **「工程表の書き出しファイル」**（`andpad_…` というファイル名の案内は削除）|
| `App\Support\AndpadScheduleSheet` | `App\Support\ScheduleImportSheet` |
| `App\Support\AndpadCategory` | `App\Support\ScheduleImportCategory` |
| `schedule_steps.source = 'andpad'` | `= 'import'`（**本番未反映で該当行 0 件**のため無償で変えられた）|
| `$andpadCount` | `$importedCount` |
| `tests/fixtures/andpad/` | `tests/fixtures/schedule-import/` |
| テスト 3 本 `Andpad*Test` | `ScheduleImport{Sheet,Category,Fixture}Test` |

⚠ **`source` の値を変えられるのは本番反映前だけ。** 反映後に変えるなら `UPDATE` が要る。

---

## 0. 着手前に読むこと

`CLAUDE.md` の Top traps 16 件と `docs/RULES.md` の Bug #1–55。とくに本件が触るのは
**#16 / #21 / #26 / #28 / #30 / #35 / #40 / #41 / #43 / #44 / #45 / #46 / #47 / #48 / #49 / #53 / #54 / #55**。

### コマンドの実行場所

⚠ **cwd ドリフトに注意**（前セッションで main repo へ流れ、実 DB 相手に `artisan tinker` を
実行しかけた）。**コマンドごとに worktree を明示する。**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/andpad-import
```

- **テストは worktree で回す。** main repo は `--no-dev` で `vendor/bin/phpunit` が無く、
  dev を入れて戻し忘れると `deploy.sh` が本番へ rsync する
- **worktree に `.env` は作らない**（実 DB へ到達不能な状態が安全策）。`APP_KEY` を環境変数で渡す:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/andpad-import && APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

⚠ `.env` も `APP_KEY` も無いと **611 errors（`MissingAppKeyException`）** になるが
**テスト本数（1152）は正しく出る**ので、数字だけ見て「緑」と誤読しないこと。

⚠ ローカル実 DB は `masa8787kanri63732`、本番は `mitsuwa-ud_masa8787kanri637`。
どちらも `CLAUDE.md` が書いている `manage` ではない。**`sudo mysql manage < x.sql` は別 DB を作る。**

### ベースライン（2026-09-01 実測）

```
OK (1152 tests, 7118 assertions)
```

`composer install` 済み。この数字が出ることを Task 0 で必ず再確認してから着手する。

---

## 0.1 ⚠ このプラン作成時の実測で判明した「設計書の誤り」3 件

設計書は正本だが、**実ファイルを再解析したところ次の 3 点が事実と違った**。
プランはこちらを採る（`docs/RULES.md` の「引き継ぎメモの件数は実測し直す」）。

| # | 設計書の記述 | 実測（2026-09-01） | 影響 |
|---|---|---|---|
| 1 | §2.2「65 工程 / **20 大工程**」 | 工程数 65 は正しいが、**大工程名は 21 種** | 分類マッピング（§3.1 G）の対象が 1 つ増える。全リストは下表 |
| 2 | §2.3「`styles.xml` に規格外の `<u val="">`」「openpyxl は読めない」 | **`styles.xml` に `<u>` 要素は 1 つも無い**（`val=""` も 0 件）。**openpyxl は普通に読める**（`['Sheet1','Sheet2']`） | 決定 1（PhpSpreadsheet 採用）は**変わらない**。SheetJS が落ちる根拠は「拡張子なし media」の切り分け実測だけで足りている。ただし**固定資産の加工でこの quirk を保とうとしてはいけない**（存在しないので） |
| 3 | §2.3「ZIP64（ローカルヘッダのサイズが `0xFFFFFFFF`）」 | 方向は正しいが正確には**混成**: ローカルヘッダ **23/23 件**が `csize = usize = 0xFFFFFFFF` ＋ 20 byte の ZIP64 extra を持つ一方、**EOCD は通常形式**（ZIP64 EOCD レコード `PK\x06\x06` も locator `PK\x06\x07` も**無い**） | 素朴な zip 実装が壊れる形はこれ。**加工版でもこの形を再現する必要がある**（Task 1） |

### 実測した 21 大工程名（行数つき / 合計 65）

| 大工程名 | 行 | 分類 | | 大工程名 | 行 | 分類 |
|---|--:|---|---|---|--:|---|
| 仮設工事 | 5 | work | | クロス・床工事 | 1 | work |
| 基礎工事 | 4 | work | | 左官工事 | 2 | work |
| 防蟻工事 | 2 | work | | タイル工事 | 1 | work |
| 足場工事 | 2 | work | | 設備工事 | 6 | work |
| 大工工事 | 5 | work | | 美装工事 | 2 | work |
| サッシ工事 | 4 | work | | **材料搬入** | 4 | **other** |
| 屋根工事 | 2 | work | | 雑工事 | 1 | work |
| 外壁工事 | 3 | work | | **検査** | 6 | **permit** |
| 塗装・防水工事 | 2 | work | | 外構工事 | 1 | work |
| 樋工事 | 1 | work | | | | |
| 電気工事 | 5 | work | | | | |
| 給排水設備工事 | 6 | work | | | | |

**行数の合計**: work **55** / permit **6** / other **4** / survey **0** / sale **0**
（`測量` `登記` `申請` `販売` を含む大工程名は実データに無い）

---

## 0.2 ⚠ 設計書に書かれていない実装上の罠（実測で見つけた 4 件）

**どれもテストを書かないと無音で壊れる。** 実装前に必ず読むこと。

### 罠 A: 各シート末尾に「ページ番号だけの行」がある

sheet1 / sheet2 とも **`r64` に `A='10'` だけ**の行がある（他セルは全部空）。印刷のページ番号。

⚠ **「A 列（大工程名）が非空なら工程行」と書くと 65 → 67 になる。**
採用条件は **「B（工程名）と E（施工開始日）がともに非空」**。
`ScheduleStepCategory` 側から見ると `'10'` は work にも permit にも当たらず `other` に落ちるので、
**黙って「10」という名前の工程が 2 件増える**（画面上も違和感が小さく気づきにくい）。

### 罠 B: sheet2 も 1〜3 行目に見出しを持つ

2 枚目は「続き」だが**独立した印刷ページ**なので、`A1` の会社名・`A2/B2` の現場名・
**`r3` の見出し行**を丸ごと繰り返し持っている（`<row>` 要素数は両シートとも 68）。

⚠ **「1 枚目だけ見出しを飛ばす」実装だと sheet2 の見出し行が工程として入る。**
見出しの判定は**シートごとに**行う。

### 罠 C: 日付は Excel シリアル値ではなく **文字列 `Y/m/d`**

セルは `t="str"`（式の結果扱い）で `<v>2026/07/27</v>` のように**文字列**が入っている
（`<f>` 要素は無い。`t="s"` = sharedStrings 参照は**見出し 18 個だけ**、`inlineStr` は 0 件）。

⚠ **`\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()` を使わない。**
シリアル値ではないので壊れる。

✅ **`App\Support\CsvDate::normalize()` がそのまま使える** — 先頭で `/` を `-` に置換し、
`checkdate()` で存在を判定する（Bug #54 で確立済み。`strtotime()` を使わない理由も同項）。

### 罠 D: ヘッダーの「工事期間」と実データの範囲は一致しない

`D1` の工事期間は **`2026/07/28〜2026/12/25`** だが、実データの最小開始日は **`2026/07/23`**。

⚠ **画面には出すが、検算に使わない。** 「工事期間の外にある工程を警告」にすると
実ファイルで**いきなり警告が出る**（実データが正しく、ヘッダーが後追いなだけ）。

### ✅ 設計書どおりで正しかったこと（再実測で確認）

- 工程 65 件 / 期間 `2026/07/23 〜 2026/12/25`
- **`期間 = 完了日 − 開始日 + 1` が 65 件すべて一致**（不一致 0 件）
- 状態: `作業前` 53 / `責任者承認済` 11 / `作業完了` 1
- 欠損は **担当会社の 5 件だけ**
- **シート分割は 60 + 5**（罠 A のページ番号行を除いた実数）。1 枚目だけだと 5 件落ちる
- **`器具取付` が 2 件**（`電気工事` と `給排水設備工事`）＝ 決定 2（大工程名を工程名に含める）の根拠
- 拡張子なし media が 2 個（`xl/media/…_client__149963__brand_output_filename`）

### 実測した文字数（上限まわり）

| 項目 | 実測の最長 | 上限 | 余裕 |
|---|--:|--:|---|
| `name` = `大工程名 / 工程名` | **21** 文字 | 100 | あり |
| `notes` = `担当会社 / 担当者 / 状態` | **184** 文字 | 255 | あり（ただし薄い） |

⚠ **実ファイルは上限に当たらない** ＝ 切り詰めのテストは**実ファイルでは書けない**。
合成行で書くこと（Task 4）。実ファイルだけで測ると「切り詰めを消しても緑」になる。

---

## 1. ファイル構成

### 新規

| ファイル | 役割 |
|---|---|
| `app/Support/ScheduleImportSheet.php` | xlsx → 行の配列。書き出し形式の判別・全シート連結・見出し/ゴミ行の除去・日付正規化 |
| `app/Support/ScheduleImportCategory.php` | 大工程名 → `ScheduleStepCategory`（5 分類） |
| `app/Http/Controllers/Housing/ScheduleImportController.php` | `form` / `preview` / `execute` |
| `resources/views/housing/properties/schedule-import.blade.php` | 取込画面（2 段。①ファイル選択 → プレビュー ②確定） |
| `database/sql/2026-09-01-add-source-to-schedule-steps.sql` | `schedule_steps.source` 列追加 |
| `tests/fixtures/schedule-import/list-format.xlsx` | **加工版**の一覧形式（Task 1） |
| `tests/fixtures/schedule-import/gantt-format.xlsx` | **加工版**のガント形式（Task 1。⚠ 実ファイル再添付待ち） |
| `tests/fixtures/schedule-import/README.md` | 加工の方針・加工スクリプト・壊れ方が残っている実測値 |

### 変更

| ファイル | 変更内容 |
|---|---|
| `composer.json` / `composer.lock` | `phpoffice/phpspreadsheet` を追加 |
| `app/Models/ScheduleStep.php` | `source` を `$fillable` に足す |
| `tests/Concerns/CreatesRealEstateSchema.php` | `schedule_steps` に `source` 列 |
| `routes/web.php` | 住宅事業ブロックに 3 ルート |
| `resources/views/_partials/_schedule_section.blade.php` | 「ANDPAD 取込」ボタン（建売のときだけ） |
| `app/Http/Controllers/Housing/PropertyController.php` | ボタン表示用のフラグを view へ |
| `lang/ja/validation.php` | `attributes` に和名（Bug #37） |

### 新規テスト

| ファイル | 内容 |
|---|---|
| `tests/Unit/Support/ScheduleImportSheetTest.php` | 解析（実ファイル ＋ 合成行） |
| `tests/Unit/Support/ScheduleImportCategoryTest.php` | 21 大工程名 → 5 分類の全件 |
| `tests/Feature/Housing/ScheduleImportTest.php` | プレビュー → 確定の往復・再取込・権限・ガント形式の拒否 |
| `tests/Feature/Schedule/ScheduleSchemaTest.php`（既存に追加なし） | `source` 列は既存の列一致テストが自動で拾う |

---

## Task 0: 作業環境を用意し、ベースラインを記録する

### 手順

```bash
cd /Users/masanori/site/manage/.claude/worktrees/andpad-import
git status --porcelain          # 空であること
git log --oneline -1            # 021a1530
composer install --no-interaction
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit 2>&1 | tail -5
```

### 完了条件

- `OK (1152 tests, 7118 assertions)` が出る
- ⚠ `Errors: 611` が出たら `APP_KEY` を渡せていない。**本数が正しいだけでは緑ではない**

**コミットしない**（環境準備のみ）。

---

## Task 1: 実ファイルを加工して固定資産に置く

### 目的

設計書 §6「実ファイルを固定資産として置く」を、**個人情報を持ち込まずに**満たす。
⚠ **手で作った正常な xlsx では ANDPAD の壊れ方を再現できない**ので、加工は
「中身の文字列だけ差し替え、ZIP の構造は保つ」形にする。

### ⚠ 個人情報の範囲は設計書が書いているより広い（実測）

| 場所 | 中身 | 加工 |
|---|---|---|
| `A1` | 自社名（株式会社ミツワ都市開発） | 差し替え |
| `B2` | 現場名（`JG保免中3号地 …`） | 差し替え |
| `F2` | 住所（`愛媛県松山市保免中2丁目292-7、292-8`） | 差し替え |
| `J2` | 工事担当 / 営業担当 の**実名 2 名** | 差し替え |
| **C 列（担当会社）× 全行** | 協力会社の実社名 **25 通り** | 差し替え |
| **D 列（担当者）× 全行** | **協力会社の個人名 約 30 名分**（第三者の個人情報） | 差し替え |

⚠ **設計書は「担当者名」としか書いていないが、実体は自社社員だけでなく
協力会社の個人名を約 30 名含む。** D 列は 1 行ずつ全部差し替える。

⚠ **A / B（大工程名・工程名）・E / H（日付）・K（期間）・L（状態）は差し替えない。**
ここが取込の検証対象そのもの。

### 加工の方法（ZIP 構造を保つ）

⚠ **Python の `zipfile` で素朴に書き直すと ZIP64 ローカルヘッダが消える**
（既定では必要になるまで ZIP64 を使わない）。それでは §0.1-3 の壊れ方が消えて
**固定資産の価値が無くなる**。

手順:

1. `xl/worksheets/sheet1.xml` / `sheet2.xml` を展開し、上表の文字列を置換する
   （`<v>` の中身のみ。`<c>` の属性やスタイル参照は触らない）
2. **ANDPAD と同じ zip レイアウトで書き戻す**:
   - ローカルヘッダの `csize` / `usize` を `0xFFFFFFFF` にし、20 byte の ZIP64 extra（0x0001）を付ける
   - 中央ディレクトリと EOCD は**通常形式**（ZIP64 EOCD レコードを書かない）
   - **エントリ名と順序を原本どおりに保つ**（拡張子なし media 2 個を含む）
3. 加工スクリプトを `tests/fixtures/schedule-import/README.md` に貼る（再現できるように）

### ⚠ 加工が「壊れ方」を消していないことを実測する

**ここを省くと、加工で正常化された xlsx を固定資産にしてしまい、
設計書 §6 の目的（本番でだけ読めないを防ぐ）が原理的に果たせなくなる。**

| # | 測ること | 期待 |
|---|---|---|
| 1 | `unzip -l` のエントリ数と名前 | 23 件・**拡張子なし media 2 個**が原本と同名 |
| 2 | ローカルヘッダの `csize`/`usize` | **23/23 件が `0xFFFFFFFF`**、extra 20 byte |
| 3 | ZIP64 EOCD (`PK\x06\x06`) / locator (`PK\x06\x07`) | **どちらも無い**（原本と同じ混成） |
| 4 | **SheetJS 0.18.5 で読む** | **依然として失敗する** |
| 5 | **PhpSpreadsheet で読む** | **2 シート・65 工程が読める** |
| 6 | 加工後のファイルを `grep` | 上表の実名・実住所が**1 件も残っていない** |

⚠ **4 と 5 の両方が要る。** 4 だけだと「壊れているが読めもしない」ゴミを固定するかもしれず、
5 だけだと「正常化されて読めるようになった」ものを固定してしまう。

### ⚠ ガント形式のファイル

**利用者に再添付してもらう**（このプラン作成時点でデスクトップに無い）。
届いたら同じ手順で加工し `tests/fixtures/schedule-import/gantt-format.xlsx` に置く。

⚠ **届くまで Task 6 の「ガント形式を拒否する」テストは書けない。**
Task 1 は一覧形式だけ先に進めてよいが、**Task 6 の完了条件に「ガント形式の拒否テストが緑」を
必ず含める**（落とすと設計書 §6 が名指しで警告している「黙って 0 件で成功に見える」経路が残る）。

### コミット

```
test(schedule): ANDPAD 取込の固定資産を置く

実ファイルの ZIP 構造（ZIP64 ローカルヘッダ・拡張子なし media）を保ったまま、
現場名・住所・担当会社・担当者を差し替えた加工版。加工後も SheetJS では読めず
PhpSpreadsheet では 65 工程読めることを実測済み。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 2: PhpSpreadsheet を導入する

### 手順

```bash
cd /Users/masanori/site/manage/.claude/worktrees/andpad-import
composer require phpoffice/phpspreadsheet
```

### 確認すること

| # | 測ること | なぜ |
|---|---|---|
| 1 | `vendor` のサイズ（導入前 39MB → 導入後） | `deploy.sh` が**毎回 vendor を rsync する**。増分を記録して利用者に伝える |
| 2 | 本番 PHP 8.3.32 の拡張 | 実測済み: zip / xml / xmlreader / xmlwriter / simplexml / mbstring / gd / zlib / dom / iconv / fileinfo ＋ `ZipArchive` すべてあり |
| 3 | `composer.lock` の差分 | 依存の追加数を記録 |

⚠ **`deploy.sh` は `composer install` を走らせない。**
ローカルで `composer install` した `vendor` を rsync で本番へ運ぶ（`CLAUDE.md`）。
⚠ **main repo は `--no-dev`。** 本番反映のとき main repo で
`composer install --no-dev` をやり直してから `./deploy.sh` すること
（worktree の dev 入り vendor をそのまま送らない）。

⚠ **`composer dump-autoload` は main repo の cwd で行う**（worktree から実行すると
autoloader の `$baseDir` に worktree パスが焼き込まれる。`CLAUDE.md` Workflow 3）。

### テスト

Task 1 の固定資産を PhpSpreadsheet で読み、**65 行・2 シート**が取れることを 1 本だけ置く
（この時点では解析クラスがまだ無いので、テストは生の PhpSpreadsheet 呼び出しでよい）。

### コミット

```
chore: PhpSpreadsheet を導入する

ANDPAD の xlsx は SheetJS 0.18.5 が読めない（拡張子なし media でクラッシュ）ため、
解析をサーバ側へ移す。設計書 §2.3 / §3 決定 1。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 3: `schedule_steps.source` 列

### 手順

**Step 1** — `database/sql/2026-09-01-add-source-to-schedule-steps.sql`:

```sql
ALTER TABLE `schedule_steps`
  ADD COLUMN `source` VARCHAR(20) NULL COMMENT '取込元。NULL=手入力 / andpad=ANDPAD 取込' AFTER `notes`,
  ADD KEY `idx_sched_source` (`schedulable_type`, `schedulable_id`, `source`);
```

⚠ **既存の `2026-08-31-create-schedule-steps.sql` にも `source` 列を足す。**
`ScheduleSchemaTest::test_raw_sql_and_test_schema_declare_the_same_columns` は
**`CREATE TABLE` 本体からしか列を拾わない**（`preg_match('/CREATE TABLE[^(]*\((.*)\)\s*ENGINE/s')`）ので、
`ALTER` だけ足してもテスト用スキーマと食い違って**赤くなる**。
本番へは `ALTER` を流し、新規構築用の `CREATE` も揃える、の両方。

**Step 2** — `tests/Concerns/CreatesRealEstateSchema.php` の `schedule_steps` に:

```php
$t->string('source', 20)->nullable();
```

⚠ `notes` の直後に置く（DDL の `AFTER notes` と順序を揃える。列一致テストは
`sort()` してから比較するので順序自体は見ていないが、読む人のために揃える）。

**Step 3** — `app/Models/ScheduleStep.php` の `$fillable` に `'source'` を足す。

⚠ **`casts()` には入れない。** 文字列のまま扱う（enum にすると
「手入力 = null」を enum で表現できず、`null` と `Manual` の 2 通りができてしまう）。

### テスト

- 既存の `ScheduleSchemaTest` が自動で拾う（列一致 ＋ 走査の空振り防止で `>= 14` 列）
  ⚠ **列が 15 になるので下限 14 はそのまま通る。** 下限を 15 に上げておくと意図が残る
- `source` を指定せずに作った工程が `null` であること（既定 = 手入力）

### コミット

```
feat(schedule): 工程に取込元（source）を持たせる

ANDPAD 由来の工程だけを入れ替えられるようにする。NULL=手入力。
設計書 §3.1 D / §4.1。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 4: `ScheduleImportSheet`（解析）

### 目的

xlsx を受け取り、**書き出し形式を判別**して、工程の配列を返す。DB には触らない。

### 公開 API（案）

```php
final class ScheduleImportSheet
{
    public const FORMAT_LIST  = 'list';    // 一覧形式（使える）
    public const FORMAT_GANTT = 'gantt';   // ガント形式（使えない）
    public const FORMAT_UNKNOWN = 'unknown';

    /** 見出し行（3 行目）の期待ラベル。判別にも列の対応にも使う */
    public const HEADERS = ['大工程名','工程名','担当会社','担当者','施工開始日',null,'施工開始時間','施工完了日',null,'施工完了時間','期間','状態'];

    public static function detectFormat(string $path): string;

    /** @return array{site_name:?string, address:?string, period:?string, rows:array, warnings:array} */
    public static function read(string $path): array;
}
```

### 実装で必ず守ること

| # | 決め事 | 理由 |
|---|---|---|
| 1 | **行の採用条件は「B（工程名）と E（施工開始日）がともに非空」** | 罠 A。`A` 非空だとページ番号行 `A='10'` を 2 件拾う |
| 2 | **見出し行の判定はシートごとに行う** | 罠 B。sheet2 も 1〜3 行目に見出しを持つ |
| 3 | **全シートを順に読んで連結する** | 決定 E。1 枚目だけだと 5 件落ちる |
| 4 | **日付は `CsvDate::normalize()` に通す** | 罠 C。文字列 `Y/m/d`。`strtotime()` を使わない（Bug #54） |
| 5 | **`期間`（K 列）は読むが保存しない** | 検算にだけ使う。保存すると内訳と合計の二重管理になる（Bug #46） |
| 6 | **`sort_order` はファイルの並び順**（連番を振り直す） | シートをまたぐので Excel の行番号は使えない |
| 7 | **ヘッダーの工事期間は表示専用** | 罠 D。実データの範囲と一致しない |

### 警告として返すもの（設計書 §5.2）

- 開始日 / 完了日 が読めない
- **完了日 < 開始日**
- 工程名が空（採用条件で落ちるので、落とした件数として報告）
- `name` が 100 文字を超えて切られた
- `notes` が 255 文字を超えて切られた
- **`期間 ≠ 完了 − 開始 + 1`**（ANDPAD 側の値と食い違う＝読み違えの検出）

⚠ **警告とエラーを分ける**（Bug #54 ④）。警告は取り込む、エラーはその行を落とす。
`assertSee` は両者を区別できないので、テストは `viewData('warnings')` /
`viewData('rowErrors')` で**役割ごとに**見る。

### テスト（`tests/Unit/Support/ScheduleImportSheetTest.php`）

**実ファイル（加工版）で固定する:**

| # | アサーション |
|---|---|
| 1 | `detectFormat()` が `FORMAT_LIST` |
| 2 | 工程が **ちょうど 65 件**（罠 A の 67 を排除） |
| 3 | **2 枚目の 5 件が入っている** — 最後の 5 件の工程名を名指しで固定（`外構工事 / 外構工事` を含む）。⚠ 件数だけだと「1 枚目を 65 行読んだ」場合と区別できない |
| 4 | 全 65 件で `期間 = 完了 − 開始 + 1` |
| 5 | 日付範囲が `2026-07-23` 〜 `2026-12-25` |
| 6 | `器具取付` が 2 件あり、`name` が `電気工事 / 器具取付` と `給排水設備工事 / 器具取付` で**区別されている** |
| 7 | 担当会社が空の行が 5 件、それでも取り込まれている |
| 8 | ページ番号行（`10`）が **1 件も混ざっていない** |
| 9 | 見出し行（`大工程名` / `工程名`）が **工程として混ざっていない** |
| 10 | ヘッダーから 現場名 / 住所 / 工事期間 が取れる |

**合成データで固定する（実ファイルは上限に当たらないため）:**

| # | アサーション |
|---|---|
| 11 | `name` が 100 文字で切られ、**警告が立つ** |
| 12 | `notes` が 255 文字で切られ、**警告が立つ** |
| 13 | `2026/02/30` が**エラー**（`checkdate()`。Bug #54） |
| 14 | 完了 < 開始 が**エラー** |
| 15 | `期間` が食い違う行が**警告**（取り込む） |

⚠ **11〜15 を実ファイルで書こうとしないこと。** 実データは上限に当たらないので、
切り詰めを消しても緑になる。

### コミット

```
feat(schedule): ANDPAD の一覧形式 xlsx を解析する

全シート連結・見出し行/ページ番号行の除去・日付の存在検証まで。DB には触らない。
設計書 §2.2 / §3.1 E。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 5: `ScheduleImportCategory`（大工程名 → 5 分類）

### 実装

設計書 §3.1 G のとおり機械的に寄せる:

```
検査 / 申請 / 許可 を含む      -> permit
測量 / 登記 を含む             -> survey
販売 / 分譲 を含む             -> sale
上記以外で「工事」を含む       -> work
それ以外                       -> other
```

⚠ **判定順が意味を持つ。** `検査` を先に見ないと `外壁下地検査` の親 `検査` が
`工事` を含まないので other に落ちる…ことは無いが、将来 `◯◯工事検査` のような
大工程名が来たとき順序で結果が変わる。**順序をテストで固定する。**

⚠ **`ScheduleStepCategory` は `casts()` 済みなので `tryFrom()` を呼ばない**（Bug #22）。

### テスト（`tests/Unit/Support/ScheduleImportCategoryTest.php`）

| # | アサーション |
|---|---|
| 1 | **実測した 21 大工程名すべて**を名指しで期待値と突き合わせる（§0.1 の表） |
| 2 | `材料搬入` が **other**（「工事」を含まないので work に落ちない） |
| 3 | `検査` が **permit** |
| 4 | 未知の語（`◯◯`）が **other**（例外を投げない） |
| 5 | 空文字が **other** |
| 6 | 実ファイルを通したときの**分類ごとの行数**が work 55 / permit 6 / other 4 / survey 0 / sale 0 |

⚠ **6 が load-bearing。** 1 だけだと「マッピングは正しいが実装が呼んでいない」を検出できない。

### コミット

```
feat(schedule): ANDPAD の大工程名を工程分類へ寄せる

21 種を 5 分類へ。色分け以外の意味は持たせない。設計書 §3.1 G。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 6: ルート ＋ `Housing\ScheduleImportController`

### ルート（3 本）

`routes/web.php` の **住宅事業 工程 CRUD ブロックの直後**に置く
（`department.access:housing` ＋ `role:executive,manager` の既存グループに合わせる）:

```php
Route::get('/properties/{property}/schedule-import', [ScheduleImportController::class, 'form'])
    ->name('housing.properties.schedule-import.form');
Route::post('/properties/{property}/schedule-import/preview', [ScheduleImportController::class, 'preview'])
    ->name('housing.properties.schedule-import.preview');
Route::post('/properties/{property}/schedule-import', [ScheduleImportController::class, 'execute'])
    ->name('housing.properties.schedule-import.execute');
```

⚠ **`preview` を `{property}` より後・`execute` より前に登録する**必要は無い
（パスが重ならない）。ただし `route:list` の並びは URI 辞書順なので、
**登録順の検証は必ず緑になる false-pass**（memory「route:list の並びは登録順ではない」）。
配線を測るならルータに実マッチさせる。

⚠ **`{property}` はここでは `HsProperty` に暗黙バインドできる**
（`ScheduleStepController` と違い、このコントローラは建売専用で親が 1 種類だから）。
メソッド引数に `HsProperty $property` と型宣言してよい。

### 2 段の受け渡し

**プレビュー → 確定**は、`AreaBuildingImportController` と同じ
**「正規化済みの行を hidden の JSON で POST し、サーバ側でもう一度検証する」**形にする。

⚠ **hidden の JSON は利用者が書き換えられる。** `execute` は
**行を 1 件ずつ再検証する**（日付は `CsvDate`、長さは切り詰め、分類は大工程名から**引き直す**）。
プレビューが返した分類をそのまま信じない。

⚠ **確定フォームは画面が描いたものをそのまま送り返す形でテストする**（Bug #47 / #54 ②）。
`AreaBuildingTestCase::parseForm($html, $needle)` と同じ流儀のヘルパを用意し、
`action` と**全 hidden**（`_token` を含む）を解析して送る。
⚠ **`$needle` は `action="…"` まで含める**（素の URL だと別のフォームを掴む）。
⚠ **`@csrf` の欠落は Feature テストでは原理的に検出できない**（`VerifyCsrfToken::handle()` が
`runningUnitTests()` で素通りする）→ **描画された `_token` hidden の存在**をアサートする。

### ガント形式の拒否（設計書 §6）

`preview` は `detectFormat()` が `FORMAT_LIST` でなければ
**「この書き出しは使えません。ANDPAD の『一覧』形式で書き出してください。」**を出して差し戻す。

⚠ **黙って 0 件にしない。** これは設計書が名指しで警告している失敗。
⚠ **判別は見出し行で行う**（ガント形式は日付グリッドなので 3 行目のラベルが揃わない）。

### 権限

- `role:executive,manager`（設計書 §5.1）
- ⚠ **staff が 403 になること**をテストで固定する
- ⚠ **他部署の物件 id を渡せないこと**（`department.access:housing` が効く）

### テスト（`tests/Feature/Housing/ScheduleImportTest.php`）

| # | アサーション |
|---|---|
| 1 | 取込画面が 200（manager） |
| 2 | **staff は 403** |
| 3 | 一覧形式を上げるとプレビューが 200 で、**65 行が表示される** |
| 4 | プレビューに**現場名・住所・工事期間**と**物件コード・物件名**が並んで出る |
| 5 | **現場名と物件名が食い違うとき警告が出る。ただし確定フォームは描画される**（止めない。設計書 §3.1 C） |
| 6 | **ガント形式を上げると「使えない」と言い、確定フォームを描画しない**（⚠ 実ファイル待ち） |
| 7 | 描画されたフォームをそのまま送り返すと **65 件の `schedule_steps` ができる** |
| 8 | できた工程の `source` が全件 `'import'` |
| 9 | `sort_order` が 1..65 で重複なし |
| 10 | 分類の内訳が work 55 / permit 6 / other 4 |
| 11 | **`actual_start` / `actual_end` が全件 null**（決定 A。実績は触らない） |
| 12 | `notes` に担当会社 / 担当者 / 状態が入っている |
| 13 | **プレビューの view に `'errors'` キーを渡していない**（Bug #53。構造テスト） |
| 14 | 確定フォームに **`_token` hidden がある** |
| 15 | 改竄した JSON（`planned_end` に `2026/02/30`）を送るとその行がエラーになる |

⚠ **13 は「全件分類」で書く**（Bug #45）。`ImportPreviewRenderTest` の流儀に合わせ、
コントローラの `view(...)` を括弧の対応で切り出し、**コメントを落としてから**測る（Bug #42 ②）。

### コミット

```
feat(housing): 建売物件に ANDPAD 工程表の取込を足す

プレビュー → 確定の 2 段。ガント形式は黙って 0 件にせず差し戻す。
設計書 §5 / §6。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 7: 画面 ＋ 詳細ページのボタン

### 取込画面（`schedule-import.blade.php`）

設計書 §5.2 のとおり。既存の取込画面（`tenant/area-buildings/import.blade.php`）の意匠に合わせる。

⚠ **`<input type="file">` に `.form-input` を付けない**（Bug #18。角丸とネイティブ装飾が消える）。
インライン style を使う。

⚠ **上限案内は本番の実値に合わせる**: `upload_max_filesize=5M` / `post_max_size=8M`。
「7MB まで」のような嘘を書かない（別タスクで指摘されている食い違いと同型）。

⚠ **`@json` を `x-data` 属性に入れない**（Bug #23）。データは `<script>` 内の名前つき関数で組む。
⚠ **`<script>` 内の `//` `/* */` コメントに `@json` や `<x-` を書かない**（Bug #30）。
書くなら `@@json`、コンポーネントタグは `{{-- --}}` へ逃がす。

### 詳細ページのボタン

`_partials/_schedule_section.blade.php` に「ANDPAD 取込」ボタン。

⚠ **この partial は 4 親が共有している。** 建売以外で ANDPAD ボタンを出してはいけない。
`$scheduleImportUrl`（null 可）を渡し、**null のときは何も描かない**形にする
（partial の中で `$owner instanceof HsProperty` を判定しない ＝ partial に親の知識を持ち込まない）。

⚠ **`PropertyController::show` 以外の 3 画面が壊れないこと**を対で固定する
（変数を渡していない画面で `Undefined variable` にならないよう `?? null`）。

⚠ **押せない理由を `disabled` なボタン自身の `title` に書かない**（Bug #43）。
権限が無いならボタンを出さない。

### テスト

| # | アサーション |
|---|---|
| 1 | 建売物件の詳細に ANDPAD ボタンが出る（manager） |
| 2 | **staff には出ない** |
| 3 | **仕入れ案件 / 分譲地PJ / 注文住宅の詳細には出ない**（4 画面すべてを見る） |
| 4 | 3 画面が 200 のまま（`Undefined variable` を出さない） |

⚠ **3 を「建売に出る」だけで済ませない。** partial 共有なので、条件を落とすと
他部署に ANDPAD ボタンが生える（Bug #41 の「経路が複数」型）。

### コミット

```
feat(housing): 建売物件の詳細に ANDPAD 取込ボタンを足す

工程表カードは 4 親の共有 partial なので、建売以外では描かない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 8: 再取込（ANDPAD 由来だけ入れ替える）

### 実装

`execute` を `DB::transaction()` で囲み:

```
1. その物件の schedule_steps のうち source = 'import' を削除
2. 読み取った行を新規に登録（source = 'import'）
3. sort_order は手入力の後ろへ続ける（手入力の max + 1 から）
```

⚠ **トランザクションで囲む理由を書き残す**（Bug #48）。
`AreaBuildingImportController` は**あえて囲んでいない**（2000 行の途中失敗で全部巻き戻ると
原因行を特定できないため）。**こちらは囲む** — 65 行と小さく、かつ
**「消してから入れる」ので途中で落ちると工程が消えたまま残る**から。方針が違うことを明記する。

### プレビューの予告（設計書 §5.2）

「ANDPAD 由来の既存 **N** 件を消して **M** 件を登録します。手で足した **K** 件は残ります」

⚠ **N / M / K を別々にアサートする。** 1 つの文言にまとめて `assertSee` すると、
どれか 1 つが壊れても緑になる（Bug #43 / #46 / #49 と同型）。

### テスト

| # | アサーション |
|---|---|
| 1 | 手入力 3 件 ＋ ANDPAD 65 件の状態で再取込 → **手入力 3 件が残り、ANDPAD が 65 件に入れ替わる** |
| 2 | 入れ替え後、**手入力 3 件の id が変わっていない**（消して作り直していない） |
| 3 | 入れ替え後、**古い ANDPAD 工程の id が 1 つも残っていない** |
| 4 | **他の物件の ANDPAD 工程が巻き添えで消えない**（`schedulable_id` ＋ `schedulable_type` の両方で絞る） |
| 5 | 予告の N / M / K がそれぞれ正しい |
| 6 | 途中で失敗したとき**元に戻る**（`creating` を監視して 40 件目で例外を投げる） |

⚠ **4 が load-bearing。** 4 親は別テーブルなので **id が衝突する**
（仕入れ案件 #12 と建売物件 #12 が両方ありうる）。`schedulable_type` を落とすと
他部署の工程が消える（`ScheduleStepController::assertOwned` が同じ理由で型を見ている）。

⚠ **6 は「安全網が測定器を鈍らせる」型に注意**（Bug #48）。
トランザクションを入れたことで、`source` の絞り込みを壊す変異が
「どうせ巻き戻るから緑」にならないか **Task 9 で測り直す**。

### コミット

```
feat(schedule): ANDPAD の再取込で ANDPAD 由来だけを入れ替える

手で足した工程は巻き添えにしない。設計書 §3 決定 3 / §3.1 D。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

## Task 9: 変異テスト

### 作法（Bug #44 / #54 で確定済み。1 つでも飛ばすと測定が無効になる）

1. **先にコミットする**（未コミットのまま変異を当てて `git checkout --` すると自分の編集ごと消える）
2. 各変異の**前**に `git status --porcelain` が**空**であることを確認
3. 変異を当てたら `git diff --stat` が**非空**であることを確認（**当たっていない変異を
   「検出しない」と誤読する事故**が過去に複数回起きている）
4. **変異は「検査対象に入るはずの場所」へ当てる**（除外リストに載っている場所へ当てて
   「検出しない」と誤読した前例がある）
5. **赤/緑ではなく、落ちた理由の文言まで**突き合わせる
6. `git checkout -- <file>` で戻す

### 当てる変異（最低 16 通り）

| # | 変異 | 期待して赤くなるテスト |
|---|---|---|
| 1 | 行の採用条件を「A 非空」に変える | 件数 65 → 67 |
| 2 | 見出し飛ばしを 1 枚目だけにする | sheet2 の見出しが混ざる |
| 3 | 1 シート目だけ読む | 件数 65 → 60 ＋ 末尾 5 件の名指し |
| 4 | `name` を工程名だけにする（大工程名を落とす） | `器具取付` の区別 |
| 5 | `CsvDate::normalize()` を `strtotime()` に戻す | `2026/02/30` が通る |
| 6 | E（開始）と H（完了）を取り違える | 期間が負・完了 < 開始 |
| 7 | 分類の `検査 → permit` を落とす | 分類内訳 permit 6 → 0 |
| 8 | 分類の判定順を入れ替える | 順序を固定したテスト |
| 9 | `source = 'import'` を書かない | 再取込で入れ替わらない |
| 10 | 再取込の削除から `source` 条件を外す | 手入力が消える |
| 11 | 再取込の削除から `schedulable_type` を外す | **他部署の工程が消える** |
| 12 | `execute` のサーバ側再検証を外す | 改竄 JSON が通る |
| 13 | ガント形式の判別を外す | 黙って 0 件で成功 |
| 14 | プレビューの view に `'errors'` キーを渡す | Bug #53 の 500 |
| 15 | 確定フォームから `@csrf` を消す | `_token` hidden のアサート |
| 16 | ボタンの建売判定を外す | 他 3 画面にボタンが出る |

### ⚠ equivalent mutant として記録するもの

| 変異 | なぜ検出されないか |
|---|---|
| `name` の 100 文字切りを外す | 実データの最長が 21 文字。**合成データのテスト（Task 4 #11）でだけ赤くなる** |
| `notes` の 255 文字切りを外す | 実データの最長が 184 文字。同上（Task 4 #12） |

⚠ **この 2 つが「実ファイルのテストでは緑」であることを実際に測って記録する。**
測らずに「合成テストがあるから大丈夫」と書くと、合成テストが実は当たっていない場合に気づけない。

### 結果の記録

このプランの末尾に**全件の表**を追記する（`検出 / 未検出 / equivalent` と落ちた理由の文言）。
未検出があればテストを足し、**足したあとに赤くなることまで確認**する。

---

## Task 10: ローカル検証

### 10-1. コンパイル済みビューを lint する（Bug #21 / #26 / #30）

⚠ **`view:cache` の成功表示では不十分**（コンパイル済み PHP を lint しない）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/andpad-import
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && APP_KEY="base64:x" php artisan view:clear
```

**期待**: `INVALID` が 0 件（現状 265 ビュー ＋ 新規 1 本）。

### 10-2. 実ブラウザで見る

⚠ **テストが原理的に測れない領域**。使い捨て SQLite ＋ `artisan serve` で:

| # | 見ること |
|---|---|
| 1 | 実ファイルを上げてプレビューが出る（65 行・現場名・住所・工事期間） |
| 2 | 現場名と物件名の食い違い警告が出て、**それでも確定できる** |
| 3 | 確定すると工程表カードに **65 本の棒**が引かれ、色が 3 系統（work 緑 / permit 青 / other 灰）になる |
| 4 | 再取込で手入力が残る |
| 5 | **ガント形式を上げると差し戻される**（⚠ 実ファイル待ち） |
| 6 | `main.scrollWidth === main.clientWidth` を **1800 / 1200 / 375px** で実測（Bug #29。超過幅は一定なので片方の幅だけでは判定できない） |
| 7 | **コンソール出力ゼロ** |

⚠ **65 工程のガントは既存の最大より大きい。** `LanePacker` の段数と縦の伸びを目で見る
（工程表の本番実データは 0 件なので、この規模で描くのは初めて）。

### 10-2 の実測結果（2026-09-01）

**環境**: 使い捨て SQLite（`storage/andpad-demo.sqlite`）＋ `php artisan serve --port=8010`。
⚠ **8000 は別プロセス（python3）が掴んでいた**ので 8010 を使った。
建売物件 1 件（`HS-2026-014 余戸南 3号地`）＋**手入力の工程 3 件**
（建築確認申請 permit / 地鎮祭 other・日付 1 つだけ / 引渡し sale）。
物件名は ANDPAD の現場名「JG見本町3号地 分譲住宅新築工事様邸」と重ならない語にして、
食い違い警告（#2）が出る状態を作った。

| # | 見たこと | 結果 |
|---|---|---|
| 1 | 実ファイルを上げてプレビューが出る | ✅ 現場名「JG見本町3号地 分譲住宅新築工事様邸」/ 住所「愛媛県松山市見本町1丁目1-1、1-2」/ 工事期間「2026/07/28〜2026/12/25」/ 読み取った工程 **65 件**、表の `tbody tr` も **65 行** |
| 2 | 食い違い警告が出て、**それでも確定できる** | ✅ 黄色帯「ファイルの現場名「…」と、取込先の物件名「余戸南 3号地」が一致しません。…このまま取り込むこともできます。」→ 確定できて `ANDPAD の工程を 65 件取り込みました。` |
| 3 | 65 本の棒 ＋ 色が 3 系統 | ✅ `getComputedStyle` の実測で **緑 `rgb(5,150,105)` 55 / 青 `rgb(59,130,246)` 6 / 灰 `rgb(107,114,128)` 4**（＋手入力の青 1・橙 1）。トラック **69**（節目 1 ＋ 68）、棒 **67**（地鎮祭は日付 1 つなので ◆）、◆ **2**（完成＝白抜き / 地鎮祭＝灰） |
| 4 | 再取込で手入力が残る | ✅ プレビューが「ANDPAD 由来の既存の工程 **65** 件を削除します / ファイルから **65** 件を登録します / 手で追加した工程 **3** 件は残ります」。確定後 `既存の 65 件を入れ替えて 65 件を登録`。DB 実測で**手入力は id 1・2・3 のまま**（実績日付も保持）、ANDPAD は id 4–68 → **69–133** に総入れ替え。`source='import'` の `actual_*` は **0 件**（設計書 §3.1 A どおり） |
| 5 | ガント形式を上げると差し戻される | ⚠ **部分的**。**ANDPAD のガント書き出しそのものは未取得のまま**。代わりに見出しが揃わない xlsx を作って上げ、`format` が `other` と判定され**赤帯つきでフォームへ差し戻される**ことをブラウザで確認した（プレビュー表は 0 行、DB も無変化）。実ファイルでの確認は依然として残る |
| 6 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で実測 | ✅ 4 画面 × 3 幅 = **12 通りすべて一致**（下表） |
| 7 | コンソール出力ゼロ | ✅ **新しいタブで ANDPAD の 4 画面だけを歩いて 0 件**（`No console logs.`）。ネットワークも該当リクエストは全部 200 |

**#6 の実測値**（`main` の `scrollWidth / clientWidth`。`documentElement` も併記）:

| 画面 | 1800px | 1200px | 375px |
|---|---|---|---|
| 建売物件 詳細（工程 68 件のガント） | 1580 / 1580（doc 1800/1800） | 980 / 980（1200/1200） | 375 / 375（375/375） |
| ANDPAD 取込フォーム | 1580 / 1580 | 980 / 980 | 375 / 375 |
| 取込プレビュー（65 行の表） | 1580 / 1580（doc 1800/1800） | 980 / 980（1200/1200） | 375 / 375（375/375） |
| 住宅事業 工程表ボード | 1580 / 1580 | 980 / 980 | 375 / 375 |

375px ではガントも表も**内側の `overflow-x: auto` に収まっている**
（ガント: `scrollWidth 940 / clientWidth 299`、プレビュー表: `496 / 301`）。
`main` を押し広げていないので Bug #29 の型ではない。

**縦の伸びと `LanePacker`**:

- 詳細カードのガントは 1 工程 1 行なので **69 行 × 34px ＝ 2346px**。崩れず、月グリッドと棒の位置も視覚的に合っている
- ボード（`/housing/schedules?status=all`）は 68 span を **8 段**に詰めた
  （`top` が 8 / 25 / 42 / 59 / 76 / 93 / 110 / 127px ＝ `LANE_TOP 8` ＋ `LANE_HEIGHT 17` × 8。
  段ごとの本数は 21 / 23 / 12 / 7 / 2 / 1 / 1 / 1 で合計 68）。行の高さがその分だけ伸び、はみ出しは無い

### ⚠ 10-2 で分かったこと（次に検証する人向け）

1. **前回プランの seed スクリプトはユーザーを executive にできていない。**
   `User::create([... 'role' => 'executive' ...])` と書いてあるが、**`role` / `status` は
   `$fillable` から外されている**（`app/Models/User.php` のコメント: 特権昇格の事故防止）ので
   黙って捨てられ **staff** になる。症状は「`/housing/*` が 403 Forbidden」「ログイン後が
   `/dashboard/executive` でなく `/dashboard/tenant`」。**作成後に明示代入すること**:
   `$user->role = UserRole::Executive; $user->status = UserStatus::Active; $user->save();`
2. **`/dashboard/executive` は検証用 DB では 500 する** —— `CreatesRealEstateSchema` は
   `ms_*` を作らないので `no such table: ms_rooms`。ANDPAD とは無関係だが、
   **ログイン直後にここへ飛ぶ**ので、**コンソール 0 件はこのページを踏まないタブで測る**こと
   （踏んだタブでは 500 の `Failed to load resource` が 1 件残り続け、`clear` しても消えなかった）。
3. **ファイル名は取込の判定に使っていない。** `list-format.xlsx` でも
   `andpad_25941203_20260901.xlsx` でも同じく通った（判定は見出しだけ）。画面の
   「ファイル名が andpad_…」は案内であって検査ではない。
4. **遅延バッジが大量に出るのは仕様どおり。** 実データの日付が今日（2026-09-01）より前で、
   ANDPAD からは実績を取り込まない（設計書 §3.1 A）ため、`+36日` `+39日` のような
   赤いバッジが並ぶ。欠陥ではない。
5. **ブラウザ道具の癖**（同じ検証をやる人向け）:
   - claude-in-chrome の `resize_window` は**最初の 1 回しか効かなかった**
     （2 回目以降は成功と返るのに `innerWidth` が変わらない。`outerWidth === 0` ＝
     CDP の viewport override が居座っている）。**幅の実測は Browser pane 側**
     （`mcp__Claude_Browser__resize_window`。`preset: "mobile"` で 375×812）で行った
   - **ファイルアップロードは 2 通りで測り、結果が一致した**: ① claude-in-chrome の
     `file_upload`（本物のファイル選択）② Browser pane で `fetch` →
     `DataTransfer` → `input.files` に代入。どちらも同じ multipart POST になる。
     Browser pane にはアップロード用ツールが無いので②が要る（固定資産を
     `public/` へ一時コピーして `fetch` した。検証後に削除済み）

---

## Task 11: ドキュメント更新 ＋ 本番反映

### ドキュメント

- `docs/BACKLOG.md` に節を足す（工程表の節の直後）
- `docs/RULES.md` に **Bug #56** として「ANDPAD の xlsx が SheetJS で読めない」を足すか判断する
  （⚠ これは**アプリのバグではなく外部ファイルの性質**なので、Bug カタログではなく
  BACKLOG の節に書くほうが素直。判断を書き残すこと）
- `CLAUDE.md` の Top traps に足すかは**足さない**（本件固有で横断的でない）

### 本番反映の順番

⚠ **DB が先・`deploy.sh` が後**（Bug #52 と同じ。列が無い DB に列を使うコードを乗せると 500）。

```
1. worktree で /commit
2. main repo で git checkout 13.x && git merge --ff-only andpad-import
3. main repo の cwd で composer install --no-dev   ← ⚠ worktree の dev 入り vendor を送らない
4. main repo の cwd で composer dump-autoload      ← ⚠ 新規クラスがあるため。worktree から実行しない
5. 本番へ ALTER TABLE を流す（php artisan tinker 経由。sudo mysql は非対話でパスワード不可）
6. ./deploy.sh
7. 本番ブラウザで目視（Task 10-2 の 1〜5 を本番で）
```

⚠ **`./deploy.sh` は利用者の明示承認が要る**（自動モードの分類器がブロックする）。
⚠ **push は利用者の明示指示があったときだけ**（現状 `13.x` は origin より 23 コミット先行）。
⚠ **本番の `view:cache` は `deploy.sh` が再生成する**が、Bug #21 / #26 は「本番だけ壊れる」前例。
**302 を「アプリは正常」の証明に使わない**（認証リダイレクトはビューを描画する前に起きる）。

---

## 変異テストの実測結果（Task 9 で実測。2026-09-01）

作法は Bug #44 / #54 どおり: ①先にコミット ②各変異の前に `git status --porcelain` が空
③`git diff --stat` が非空で着弾を確認 ④`git checkout --` で戻す ⑤**落ちた理由の文言まで**突き合わせる。
実行器は `scratchpad/mutate.py`（同じ手順を機械化したもの）。

| # | 変異 | 結果 | 落ちたテストと理由 |
|---|---|---|---|
| 1 | 行の採用条件に大工程名を含める | 検出 | `..._reads_every_step_from_every_sheet`（rowErrors が 2 件増える） |
| 2 | 見出し行もデータとして読む | 検出 | 同上 |
| 3 | 1 シート目だけ読む | 検出 | 同上 — `actual size 60 matches expected size 65` |
| 4 | 大工程名を工程名に含めない | 検出 | `..._keeps_the_five_steps_that_only_exist_on_the_second_sheet` |
| 5 | 日付検証を `strtotime()` に戻す | 検出 | `..._rejects_a_date_that_does_not_exist` |
| 6 | 開始日に完了日の列を読む | 検出 | `..._reads_every_step_from_every_sheet` |
| 7 | 分類から `検査` を落とす | 検出 | `ScheduleImportCategoryTest`「大工程名『検査』の分類」 |
| 8 | 分類の判定順を入れ替える | 検出 | 同「大工程名『仮設工事』の分類」 |
| 9 | `source` を書かない | 検出 | `..._submitting_the_rendered_form_imports_every_step` |
| 10 | 削除から `source` 条件を外す | 検出 | `..._reimporting_replaces_only_the_andpad_steps` |
| 11 | 削除から `schedulable_type` を外す | 検出 | `..._reimporting_does_not_touch_another_owners_steps` |
| 12 | サーバ側の再検証を外す | 検出 | `..._a_tampered_payload_is_rejected_and_changes_nothing` |
| 13 | 書き出し形式の判別を外す | 検出 | `..._a_file_that_is_not_the_list_format_is_rejected`（302 のはずが 200） |
| 14 | view に `'errors'` キーを渡す | 検出 | **`ImportPreviewRenderTest`**（既存の全件走査が自動で拾った） |
| 15 | 確定フォームから `@csrf` を消す | 検出 | `..._the_confirm_form_carries_a_csrf_token` |
| 16 | partial のボタン条件を外す | 検出 | `..._the_import_button_appears_only_on_the_tateuri_detail_page`（他 3 画面が 500） |
| 17 | ボタンの権限判定を外す | **初回 未検出 → テスト追加後 検出** | 下記 |
| 18 | 工程名の 100 文字切りを外す | 検出 | `..._truncates_and_warns_on_a_long_step_name`（123 ≠ 100） |
| 19 | 備考の 255 文字切りを外す | 検出 | `..._truncates_and_warns_on_long_notes`（403 ≠ 255） |
| 20 | 全角空白の trim をバイト単位に戻す | 検出 | `..._every_parsed_value_is_valid_utf8` |

**初回 19/20 → テストを 1 本足して 20/20。**

### ⚠ #17 が測れていなかった理由（Bug #48 の型）

`$scheduleImportUrl = $scheduleCanEdit ? route(...) : null;` の権限判定を外しても
**画面の見た目が変わらない**。工程表カードが `@if($scheduleCanEdit)` で編集 UI 全体を
隠しているので、partial の判定がコントローラの判定を backstop してしまう。

→ **応答でなく「主機構が仕事をしたか」を直接見る**（`viewData('scheduleImportUrl')` が
staff で null、manager で URL）。これで partial 側とコントローラ側が独立に固定される。

### ⚠ 18 / 19 は「実ファイルでは equivalent」

実データの最長は工程名 22 文字 / 備考 174 文字で**どちらも上限に当たらない**。
よって切り詰めを外しても**実ファイルのテストは全部緑**で、赤くなるのは合成データの
2 本だけ。実ファイルだけで測ると検出力ゼロになる。

### ⚠ 測定器そのものの罠（実測）

- **変異ランナーが phpunit の出力デコードで落ちた** —— #20 の変異が壊れた UTF-8 を
  出力に混ぜるため。`errors='replace'` を付けるまで、**その回の測定は途中で止まり
  ツリーが汚れたまま**だった（`git status` で気づいた）。
- **`APP_KEY="base64:x"` は無効な鍵。** 構造テストは通るが暗号を使う経路が
  `Unsupported cipher or incorrect key length` で落ち、**14 本すべてが同じ理由で赤**になる。
  「変異が効いた」と誤読しかけた。鍵は必ず 32 byte を base64 で渡す。

---

### 改名後の再測定（2026-09-01。commit `78108891`）

画面の文言と `source` の値を変えたので、**それに依存していた変異 4 通りを当て直した**。
とくに危ないのは「ボタンの有無」を見るテストで、判定文字列を `ANDPAD` から
`工程表を取り込む` に替えた瞬間、**カード見出しの「工程表」に部分一致する語を選ぶと
false-pass する**（Bug #43 / #46 の型）。そこで**文言と URL の両方**で見る形にした。

| 変異 | 結果 | 落ちたテストと理由 |
|---|---|---|
| partial のボタン条件を外す（4 親すべてに出す） | 検出 | `..._the_import_button_appears_only_on_the_tateuri_detail_page`「**建売以外にボタンが出ている**」|
| ボタンの文言を「取り込む」に変える | 検出 | 同上「**建売にボタンが出ていない**」（＝文言が pin されている） |
| `source` を書かない | 検出 | `..._submitting_the_rendered_form_imports_every_step` ほか **3 本** |
| 削除から `source` 条件を外す | 検出 | `..._reimporting_replaces_only_the_imported_steps`「actual size 65 matches expected size 68」ほか 2 本 |

⚠ 併せて `assertStringNotContainsString('schedule-import', $html)` を足した（**URL の漏れ**も見る）。
文言だけだと、将来ボタンの言い方を変えた人が負のアサートを緩めてしまう余地が残る。

⚠ **`assertSee('工程表の取込')` は `<title>` / `<h1>` / パンくずの 3 箇所に一致する**（改名前は 2 箇所）。
「画面が開く」ことの確認としては十分だが、**`<h1>` を消しただけの変異は捕まえられない**。
意図して pin していないことを記録しておく。

## 完了の定義

- [x] Task 0〜10 が完了し、それぞれコミットされている（Task 11 の本番反映だけ未実施）
- [x] `./vendor/bin/phpunit` が green —— **OK (1202 tests, 8065 assertions)**（2026-09-01 実測。改名後に再実測）
- [x] **変異 20 通りを実測**し、結果の表がこのプランに追記されている（未検出 1 件はテストを足して赤を確認済み）
- [x] コンパイル済みビューの `php -l` が **0 件 INVALID**（266 本。2026-09-01 に再実測）
- [ ] **ガント形式の実ファイルが固定資産にあり、拒否テストが緑**（⚠ **再添付待ち。唯一の未了項目**）
- [x] 加工版の固定資産が**壊れ方を保っている**ことを 6 点で実測済み（Task 1）
- [x] 加工版に**実名・実住所が 1 件も残っていない**ことを grep で確認済み
- [x] 実ブラウザで **7 点中 6 点**を確認済み（上記「10-2 の実測結果」）。残る #5 は上の実ファイル待ちと同根
- [x] `docs/BACKLOG.md` を更新
- [ ] 本番反映は **DB → deploy → 目視** の順で、利用者の明示承認を得てから

---

## ⚠ 着手をブロックしている事項

| # | 事項 | 状態 |
|---|---|---|
| 1 | **ガント形式の実ファイル**（`【ANDPAD工程表】…_20260831_2313.xlsx`） | **再添付待ち**。Task 6 #6 と Task 10-2 #5 と完了の定義に効く |
| 2 | 一覧形式の実ファイル | ✅ デスクトップにあり（`andpad_25941203_20260901.xlsx`） |

⚠ **ANDPAD の書き出しは同じ内容でもバイト単位では再現しない。** 2026-09-01 に同じ現場を
2 回書き出したところ sha256 が違った（`a3f72ef6…` 46967 bytes / `a2485803…` 46971 bytes）。
展開後の `sheet1.xml` / `sheet2.xml` のサイズ・見出し・工程 65 件はどちらも同一で、
差は zip の圧縮結果だけ。**固定資産の同一性を sha256 で固定しないこと**（書き出し直すと落ちる）。
内容で固定する（工程 65 件・見出し 12 列・シート 2 枚）。
| 3 | 固定資産の PII 方針 | ✅ 決定済み — **氏名・住所を差し替えた加工版**を置く |
