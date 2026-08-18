# 賃貸マンション CSV 取込のテスト整備 — 設計書

作成日: 2026-08-18
対象: `app/Http/Controllers/Admin/MansionImportController.php`（1,489 行）
      ＋ 両取込コントローラが共有するヘルパー

---

## 1. 背景

管理画面の CSV 一括取込は 2 本ある。

| コントローラ | 行数 | git 履歴 | テスト |
|---|---:|---|---|
| `Admin/MansionImportController` | 1,489 | **1 コミット**（追加以来ノータッチ） | 0 |
| `Admin/TenantImportController` | 1,371 | 7 コミット | 0 |

既存の `tests/Feature/Admin/ImportValidationFeedbackTest.php` はエラー表示しか見ておらず、
取込の振る舞い（何行が入るか・何が入るか）を固定したテストは**両方とも 1 本も無い**。

一括取込は 1 リクエストで大量の行を書くため、欠陥が出たときの被害が広い。
しかも管理画面の裏側なので、壊れていても画面上は「完了しました」と出るだけで気づけない
（Bug #52 と同じ「無音で壊れる」型）。

賃貸マンションを先に選んだ理由は、**本番の `ms_*` が全テーブル 0 件**だから。
つまり壊れていてもまだ誰も踏んでおらず、逆に言えば**これから一括投入するときに初めて踏む**。
既存データを壊す危険が無いうちに固定しておく。

---

## 2. 実装を読んで確定した事実

### 2.1 取込の構造

プレビューと確定は**別の HTTP リクエスト**で、確定側は画面が持ち回った base64 から CSV を復元し、
**行バリデーションを最初からやり直す**。

```
POST /admin/mansion-import/xxx            → 行検証 → プレビュー画面（csv_data に base64 を埋める）
POST /admin/mansion-import/xxx (confirmed) → base64 復元 → 行検証をやり直し → beginTransaction → create → commit
```

`DB::transaction()` は使わず `beginTransaction` / `commit` / `rollBack` を手書きしているが、
経路としては正しく動いている。

行の検証は「1 行ずつ検査し、不正なら `$errors[]` に積んで `continue`」。
つまり**不正行はその 1 行だけ落ちる**設計になっている。この設計自体は正しい。

### 2.2 共通ヘルパーは逐語コピーで、既に一度 drift している

両コントローラの private ヘルパーを `token_get_all()` でコメント除去してから突き合わせた結果:

| ヘルパー | 一致 |
|---|---|
| `loadCsv` | 一致 |
| `normalizeDate` | 一致 |
| `toCsvLine` | 一致 |
| `buildCsvResponse` | 一致 |
| `getNextPropertyCodeNum` | **不一致** |

`getNextPropertyCodeNum` だけ実装が違う:

- Mansion: `MsProperty::orderByDesc('id')->first()` のコード末尾 + 1
- Tenant: `Property::withTrashed()->where('code','like','T-%')->orderByDesc('code')` のコード末尾 + 1

docblock は 4 本とも「`TenantImportController::xxx()` からの逐語コピー」と明記している。
**コピーで揃えるという運用は既に破れている**（Bug #41「同じ処理の経路が複数あり片方だけ直す」型）。

### 2.3 `normalizeDate` に確定的な欠陥が 2 件（実測）

```php
if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) { ... }
```

`strtotime()` は存在しない日付を**繰り上げて成功を返す**ため素通りし、
逆に UTC（`config/app.php` の `timezone` は `'UTC'`）では `strtotime('1970-01-01') === 0` が
falsy なので拒否される。実測値:

| 入力 | 現在の返り値 | あるべき |
|---|---|---|
| `2026-02-30` | `'2026-02-30'` | null |
| `2026-04-31` | `'2026-04-31'` | null |
| `0000-01-01` | `'0000-01-01'` | null |
| `1970-01-01` | **null** | `'1970-01-01'` |
| `2026-02-29` | `'2026-02-29'` | null（2026 は閏年でない） |
| `2026-2-3` | `'2026-02-03'` | 現状維持 |
| `2026/04/01` | `'2026-04-01'` | 現状維持 |

`checkdate()` が上記すべてを正しく判定することは実測済み
（`checkdate(1,1,0)` は false なので `0000-01-01` も落ちる）。

呼び出しは契約日・入居日・退去日・開始日・終了日で **11 箇所**（Mansion 6 / Tenant 5）。

本番 MySQL は strict mode なので `'2026-02-30'` の INSERT は `Incorrect date value` で例外になり、
`catch` が `rollBack()` する。つまり**打鍵ミス 1 行で数百行の取込が丸ごと失敗**し、
画面には生の SQL エラー文が出る。

修正後は既存のエラー経路（`$errors[]` + `continue`）に乗るので、
**その 1 行だけがエラー表示になり、残りは取り込まれる**。新しい分岐は要らない。

### 2.4 テスト用スキーマに UNIQUE 制約が 1 本も無い

| | 本番 DDL `database/sql/create_mansion_tables.sql` | `tests/Concerns/CreatesMansionSchema.php` |
|---|---|---|
| `ms_properties.property_code` | `UNIQUE KEY uk_ms_properties_code` | 無し |
| `ms_rooms (property_id, room_number)` | `UNIQUE KEY uk_ms_rooms_property_room` | 無し |
| `ms_parkings (property_id, parking_number)` | `UNIQUE KEY uk_ms_parkings_property_number` | 無し |

重複判定が壊れていても、テストでは 2 行目が素直に INSERT されて**緑**になり、
本番だけ「UNIQUE 違反 → 例外 → `rollBack()` → 取込全体が失敗」になる。
Bug #40 と同型の、テストが原理的に検出できない穴。**測定器の側から直す必要がある。**

---

## 3. スコープ

**やる**: 共通ヘルパーの切り出し ＋ `MansionImportController` の 6 経路
（物件 / 部屋 / 駐車場 / 入居者 / 部屋契約 / 駐車場契約）

**やらない**（理由つき）:

| 項目 | やらない理由 |
|---|---|
| `TenantImportController` の execute 5 経路のテスト | 今回のスコープ外。共用部品の Unit テストで**間接的にしか守られない**状態が残ることを明記して残す |
| `getNextPropertyCodeNum` の一本化 | 2 実装は意図的に別物（`MS-` と `T-` で採番規則が違う）。Mansion 側の `orderByDesc('id')` は UNIQUE 違反を起こしうるので、**テストで測ってから**直すか決める |
| `DB::transaction()` へのリファクタ | 現状の手書き `beginTransaction`/`commit` は正しく動いており、触る理由がない |
| 本番デプロイ | ユーザーの明示承認を得てから別途 |

**記録に留める**: 確定リクエストは `csv_data` の base64 を無検証で受ける
（`csv_file` の 10MB 上限はプレビュー時のみ）。`role:executive` 限定の管理画面なので今回は直さない。

---

## 4. 設計

### 4.1 共用部品の切り出し（振る舞い不変）

完全一致の 4 本を `app/Support/` へ移し、**HTTP を知らない純粋な部品**にする。
`app/Support/` はフラットなクラス名が流儀（`AreaConverter` / `TsuboPrice` / `VacancyRate` / `FloorNumber`）
なのでそれに合わせる。

| 新クラス | 中身 | 由来 |
|---|---|---|
| `App\Support\CsvImportReader` | 文字コード判定＋BOM 除去、ヘッダー→内部キー写像、行抽出、必須ヘッダー検査 | `loadCsv` の純粋部分 |
| `App\Support\CsvImportTemplate` | CSV 行のエスケープ、BOM 付きダウンロード応答 | `toCsvLine` / `buildCsvResponse` |
| `App\Support\CsvDate` | `YYYY-MM-DD` 正規化 | `normalizeDate` |

コントローラに残るのは「ファイル取得 / base64 復元 / `back()->with('error')`」だけ。

**エラーの返し方**: `CsvImportReader::parse()` はファイル書式の異常
（データ行が無い / 必須ヘッダーが無い）を `CsvImportException` で投げる。
`getMessage()` が**現在の日本語文言をそのまま**返すので、画面の文言は変わらない。
コントローラ側は `loadCsv()` の中で捕まえて従来どおり `back()->with('error', $e->getMessage())` する。

戻り値でエラーを表現しない理由は、現在の `loadCsv()` が
「`array` または `RedirectResponse`」という型の定まらない値を返しており、
呼び出し側 11 箇所すべてに `instanceof` 分岐を強いているため。
純粋な部品にする以上、正常系の戻り値だけを持たせる。

**この切り出しは独立コミットにし、`CsvDate` の修正は次のコミットに分ける。**
テナント側（実データあり・テスト 0）に手が入るので、
「移動で壊れた」と「修正で挙動が変わった」を混ぜると切り分けられなくなる。

### 4.2 `CsvDate::normalize()` の修正

`strtotime()` の真偽判定をやめ `checkdate()` にする。

```php
public static function normalize(string $value): ?string
{
    $value = str_replace('/', '-', $value);
    if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        return null;
    }
    [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if (! checkdate($mo, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}
```

`/` の受理とゼロパディングは現状維持。

### 4.3 テスト用スキーマの是正

`tests/Concerns/CreatesMansionSchema.php` に本番 DDL の UNIQUE 3 本を追加する。

これで既存の賃貸 M テスト（解約精算など）が落ちるなら、**それ自体が見つける価値のある欠陥**
なので、落ちた場合は緑に戻す前に原因を追う。

### 4.4 テスト構成

| ファイル | 中身 |
|---|---|
| `tests/Unit/Support/CsvImportReaderTest.php` | BOM / Shift_JIS / CRLF / 引用符内カンマ / 必須ヘッダー欠落 / 空ファイル / 列数不足の行 |
| `tests/Unit/Support/CsvDateTest.php` | 境界値。`2026-02-30` と `1970-01-01` を**値ごと明示的に置く** |
| `tests/Feature/Admin/MansionImportTest.php` | 6 経路 × 「プレビュー → 確定」の往復＋各経路固有のリスク 1〜2 本 |
| `tests/Feature/Admin/MansionImportTemplateTest.php` | テンプレート往復ラチェット（全件分類） |

#### 往復テストの作法（Bug #47）

URL を `assertSee` で見るのではなく、**画面が実際に描画したフォームを分解して送り返す**。
プレビュー応答から `csv_data` hidden を取り出し、それを `confirmed=1` と一緒に POST する。
これで「プレビューは出るが確定が動かない」型の欠陥を拾える。

#### テンプレート往復ラチェット（Bug #45）

`download*Template` を reflection で**全件列挙**し、落としたテンプレート CSV をそのまま
対応する execute へアップロードして、プレビューが 1 件以上通ることを検証する。

- 「直した分を配列に並べる」形にしない。タブが増えたら**自動で検査対象に入る**
- ヘッダー整合・サンプル列数・サンプル値が自前のバリデーションを通ること・BOM の読み戻しを
  一度に押さえられる（テンプレートのヘッダーは `array_keys($columnMap)` から導出されているため）
- 列挙が空振りして緑になる事故を防ぐため、**列挙件数の下限（6）も併せて固定**する

### 4.5 検出力の実測（変異テスト）

「テストが緑」は検証にならない。以下の作法を守る（Bug #44）:

1. **先にコミットしてから**変異を当てる
2. 各変異の**前**に `git status --porcelain` が空であることを確認する
3. 「赤になった」だけでなく、**落ちた理由の文言まで**突き合わせる
4. 変異は「検査対象に入るはずの場所」へ当てる

最低限、次の変異で赤になることを実測する:

- `CsvDate::normalize()` を `strtotime()` 版に戻す → `CsvDateTest` が `2026-02-30` で赤
- `CsvDate::normalize()` から `1970-01-01` の期待値を消しても赤が保たれるか（値が load-bearing か）
- テンプレートラチェットの列挙を 1 件に絞る → 下限 6 で赤
- 各経路の重複判定を潰す → 対応する Feature テストが赤
- `CreatesMansionSchema` の UNIQUE を外す → 重複を検出しているテストが緑に戻る（＝ UNIQUE が load-bearing であることの証明）

---

## 5. 成果物

| 種別 | ファイル |
|---|---|
| 新規 | `app/Support/CsvImportReader.php` / `CsvImportTemplate.php` / `CsvDate.php` |
| 修正 | `app/Http/Controllers/Admin/MansionImportController.php` / `TenantImportController.php` |
| 修正 | `tests/Concerns/CreatesMansionSchema.php`（UNIQUE 3 本） |
| 新規 | `tests/Unit/Support/CsvImportReaderTest.php` / `CsvDateTest.php` |
| 新規 | `tests/Feature/Admin/MansionImportTest.php` / `MansionImportTemplateTest.php` |
| 追記 | `docs/RULES.md`（`strtotime` で日付を検証する罠を Bug #53 として） |

DB 変更は無い。本番反映は `./deploy.sh` のみで足りる（承認後）。
