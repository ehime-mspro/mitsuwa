# 取込の入力チェックの包み忘れを走査テストで止める — 設計書

作成日: 2026-09-25
対象: `app/Http/Controllers` 配下の `*ImportController.php`（8 本）を読むテスト。**本番のコードは変えない**
前例: @docs/superpowers/plans/2026-09-24-import-preview-back.md（Bug #64 の修正。レビューの表の M4 が今回の元）
　　　`tests/Feature/ImportControllerReturnPathScanTest.php`（戻り先の書き方を全件分類で数える既存の走査）

---

## 1. 背景と依頼

取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。そこに載ったフォームから送って
断られると、`back()` や、try で包んでいない入力チェックの既定の戻り先（リファラー）へ GET で戻って **405** になる
（docs/RULES.md Bug #64）。2026-09-24 に 4 本（テナント・賃貸マンション・ZEAL 会員・工程表）を直し、
`ImportControllerReturnPathScanTest` が `back(` などの書き方を数えて止めるようにした。

ただし**入力チェックの既定の戻り先は、コードに `back(` が現れないので走査に見えない**。今の 4 本は実際に送って
確かめるテストが守るが、**新しい取込には守り手がいない**（同テストの docblock・BACKLOG の範囲外の 1 項目）。
2026-09-24 のレビューが「try で包んで `redirectTo()` を渡しているかを機械的に見る形」を提案した（計画書のレビューの表 M4）。

この文書で「**包む**」は、入力チェックの例外を `catch (ValidationException $e)` で受けて
`throw $e->redirectTo(route('…'));` と戻り先を固定して投げ直すことを指す（Bug #64 の規約の形）。

対話で決まったこと（2026-09-25）:

| 論点 | 決定 |
|---|---|
| 守る範囲 | **案 1**: 取込のコントローラ（`*ImportController.php`）だけをテストで守る。本番のコードは変えない。アプリ全体の安全網（入力チェックで断られたときの戻り先を例外の処理で変える案 2）は別の作業 |
| 既存の走査の穴（今の URL へ戻す形）| **今回塞ぐ**（§2.4・§4.7）|
| 確かめ方 | **ソースを読む**（§3 の 1）。実際に送って確かめる案・両方の案は採らない |
| 設計の 4 節 | 全体の形・判定の規則・既存の走査に足す検出・確かめ方と記録、いずれも承認済み |

---

## 2. 調査で分かった事実（2026-09-25 実測・`13.x` = `85b5662a`）

### 2.1 取込のコントローラと入力チェック

取込のコントローラは **8 本**、入力チェックは **10 か所**で、すべて `$request->validate([...])` の形
（`Validator::make`・`ValidationException::withMessages`・FormRequest は 0 件）。

**包んである 7 か所**（どれも実際に送って確かめるテストがある: `Admin\TenantImportRejectionTest`・
`Admin\MansionImportRejectionTest`・`Admin\ZealMemberImportControllerTest`・`Housing\ScheduleImportTest`・
`Approval\ApprovalUserImportTest`）:

| ファイル:行 | メソッド | 戻り先 |
|---|---|---|
| `Admin/TenantImportController.php:1265` | `loadCsv` | `admin.tenant-import`（そのタブ）|
| `Admin/MansionImportController.php:1392` | `loadCsv` | `admin.mansion-import`（そのタブ）|
| `Admin/ZealMemberImportController.php:234` | `loadCsv` | `admin.zeal.member-import` |
| `Approval/UserImportController.php:73` | `preview` | `approvals.admin.users.import` |
| `Approval/UserImportController.php:103` | `execute` | `approvals.admin.users.import` |
| `Housing/ScheduleImportController.php:56` | `preview` | `housing.properties.schedule-import.form` |
| `Housing/ScheduleImportController.php:91` | `execute` | `housing.properties.schedule-import.form` |

- 7 か所とも、入力チェックのすぐ外側の try で包んでいる（同じメソッドの中でほかの try に入れ子になっていない）。
  catch は `ValidationException` を名指しし、本体はコメントを除くと `throw $e->redirectTo(…);` の 1 文だけ
- `ValidationException` は 5 本とも冒頭の `use Illuminate\Validation\ValidationException;` で読み込んでいる

**包んでいない 3 か所**（今は 405 にならない）:

| ファイル:行 | メソッド | 405 にならない理由 |
|---|---|---|
| `Admin/CustomerImportController.php:28` | `execute` | 確認画面の URL が取込の画面と同じ（GET・POST とも `/admin/customers/import`）。リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）|
| `Tenant/AreaBuildingImportController.php:85` | `execute` | 確認は画面の中（SheetJS）で行い、送信元は GET の取込の画面（同 200）|
| `Zeal/SheetImportController.php:58` | `updateUrls`（PUT）| 送信元は GET の URL 編集画面（`editUrls` が描く `zeal/simulations/sheet-import/urls.blade.php`）だけ（2026-09-25 にルートとビューで確認）|

取込のコントローラの try と catch（合わせて 26 組。try 1 つに catch 1 つ）:

| catch の型 | 数 |
|---|---|
| `ValidationException $e` | 7（上の 7 か所）|
| `\Exception $e` | 12（顧客 1・テナント 5・賃貸マンション 6。どれも確定の書き込みを囲む）|
| `CsvImportException $e` | 4 |
| `\Throwable $e` | 2（`Zeal/SheetImportController` の `preview`・`apply`）|
| `UniqueConstraintViolationException`（変数なし）| 1（周辺ビル）|

- まとめた `use`（`use A\{B, C};`）は 0 件。クラスの中の `use`（トレイト）も 0 件
- `loadCsv()` の呼び出し元（テナントの 5 か所など）は、`\Exception` の try の**外**で呼んでいる

### 2.2 送信を受けるルート

取込のコントローラが受ける送信先は **22 本**。通すには権限が 5 種類（`role:executive`・`approval.admin`・
`department.access:housing` / `tenant` / `zeal`）、ルートの引数に `{property}`（`HsProperty`）と
`{simulation}`（`ZealSimulation`）のデータが要る。`updateUrls` は全項目が任意（`nullable`）なので、
空で送ると入力チェックを通って保存まで進む。

### 2.3 Laravel の事実（12.55.0）

| 事実 | 場所 |
|---|---|
| 入力チェックで断ったときの戻り先は `redirect($exception->redirectTo ?? url()->previous())` | `Foundation/Exceptions/Handler.php:782` |
| FormRequest の戻り先は `$redirect`・`$redirectRoute`・`$redirectAction` が無ければ `url()->previous()` | `Foundation/Http/FormRequest.php:190` |
| `Validator::validated()` も失敗すると投げる | `Validation/Validator.php:642〜648` |
| 静的な `Validator::validate($data, $rules)` がある | `Validation/Factory.php:142` |
| `UrlGenerator::to()` は相対パスを今のホストの URL に組み直す（`redirect($request->path())` は今の URL へ戻る）| `Routing/UrlGenerator.php:213〜` |
| 基底の `App\Http\Controllers\Controller` は `ValidatesRequests` を使っていない（`$this->validate()` は今は無い）| — |
| アプリの FormRequest は `App\Http\Requests\ProfileUpdateRequest` だけ。Laravel 同梱に `Illuminate\Foundation\Auth\EmailVerificationRequest` がある | — |

### 2.4 既存の走査の穴（今の URL へ戻す形）

既存の `ImportControllerReturnPathScanTest::returnsToReferer()` は、次の 10 通りをどれも拾わない（実測）。
取込のコントローラには、どれも 0 件。

`url()->current()`・`url()->full()`・`URL::current()`・`URL::full()`・`app('url')->current()`・`Request::url()`・
`Request::fullUrl()`・`$request->getUri()`・`$request->getRequestUri()`・`throw $e->redirectTo(url()->current())`

新しい走査は包み方だけを見て、`redirectTo()` に渡す戻り先の中身は既存の走査に任せる（§4.4）。
この穴が残ると、最後の `throw $e->redirectTo(url()->current())` は**どちらの走査にも見つからずに 405 になる**。

### 2.5 ほかのコントローラに内蔵された取込

`*ImportController` 以外で取込らしい送信を受けるルートは 2 本（`RealEstate\ProcurementController::bulkImportCosts`・
`RealEstate\ProjectController::bulkImportCosts`）。どちらも詳細画面（GET）から JS で送るので、405 の形ではない → 範囲外。

---

## 3. 方針（確かめ方の比較）

| 案 | 確かめ方 | 見落とすもの | 準備 |
|---|---|---|---|
| **1（採用）ソースを読む** | トークンに分けて読み、入力チェックの呼び出しごとに包み方を見る。FormRequest は Reflection | トレイト・親クラス・サービスに移した入力チェック（今は 0 件）・見本に無い書き方 | なし |
| 2 実際に送る | 22 本へ POST 専用の架空の URL をリファラーにして空で送り、戻り先を見る | 1 本につき最初の 1 か所だけ・空でも通る入力チェック（`updateUrls`）| 権限 5 種を満たす利用者と 2 種のデータ。送信先が増えるたびに準備を足す |
| 3 両方 | 1 と 2 | — | 2 と同じ |

1 を採る理由:
- ファイルの中の呼び出しを、どの経路で通るかに関係なく全部見る（2 つ目以降の入力チェックも見る）。
  2 のほうが多く見つけるのはトレイト・サービスに移したときだけで、今は 0 件
- 見落とす範囲が既存の走査と同じになり、「見えないもの」の説明を両方で揃えられる
- 正規表現でなくトークンで読むのは、try と catch の入れ子・catch の並び順・文字列やコメントの中の `validate(` を
  取り違えないため（Bug #45 ④「ブロック抽出の区切りが素朴」）

---

## 4. 設計

### 4.1 ファイル構成

| ファイル | 役割 |
|---|---|
| 新 `tests/Feature/ImportControllerValidationRedirectScanTest.php` | 今回の本体。入力チェックの包み忘れを全件分類で止める（§4.3〜§4.6・§4.8）|
| 新 `tests/Concerns/ScansImportControllers.php` | `*ImportController.php` の列挙と下限（8 本）を 1 か所にまとめる。既存の走査と新しい走査が必ず同じ集合を見る |
| 変 `tests/Feature/ImportControllerReturnPathScanTest.php` | 列挙をトレイトへ移す・「今の URL」の検出を足す・docblock を更新（§4.7）|
| 変 `docs/RULES.md`・`docs/BACKLOG.md`・`CLAUDE.md` | §4.10 |
| 新 この設計書・計画書 `docs/superpowers/plans/2026-09-25-import-validation-scan.md` | — |

- `app/`・`resources/`・`routes/` は 1 行も変えない
- 新しい走査を別ファイルにするのは、既存の走査（戻り先の書き方を数える）と見るもの・例外リストの意味が違うため。
  1 本にまとめると 400 行を超え、2 種類の例外リストが並ぶ

### 4.2 列挙（`ScansImportControllers`）

- `app_path('Http/Controllers')` 配下を再帰で見て、名前が `ImportController.php` で終わるファイルを
  「プロジェクトルートからの相対パス => 絶対パス」で返す（相対パスで並べ替える）。既存の `scan()` の列挙をそのまま移す
- 下限は 8 本（2026-09-25 実測）。下回ったら列挙が空振りしている
- ⚠ 既存の定数の注記「このブランチは approval-phase1 の上に積んである」は、approval-phase1 が `13.x` に入ったので外す

### 4.3 新しい走査: 入力チェックの例外を投げる呼び出し（検出）

`token_get_all()` で読み、コメント（`T_COMMENT`・`T_DOC_COMMENT`）と空白を飛ばしたトークンの並びで探す。
文字列の中身（`T_CONSTANT_ENCAPSED_STRING` など）は 1 つのトークンなので、中の `->validate(` には当たらない。

| 形 | トークン | 例 |
|---|---|---|
| `->validate(`・`?->validate(` | `->` か `?->`、名前 `validate`、`(` | `$request->validate([...])`・`Validator::make(...)->validate()`・`$this->validate(...)` |
| `->validateWithBag(` | 同上、名前 `validateWithBag` | `$request->validateWithBag('x', [...])` |
| `->validated(` | 同上、名前 `validated` | `$validator->validated()` |
| `::validate(` | `::`、名前 `validate`、`(` | `Validator::validate($data, $rules)` |
| `::withMessages(` | `::`、名前 `withMessages`、`(` | `ValidationException::withMessages([...])` |
| `new ValidationException` | `new`、`ValidationException` に解決される名前 | `new ValidationException($validator)` |
| `ValidationException::class` | `ValidationException` に解決される名前、`::`、`class` | `throw_if($v->fails(), ValidationException::class, $v)` |

- メソッド名は PHP と同じく大文字小文字を区別しない（`->Validate(` も拾う）。名前は完全一致で見る
  （`validateSales(`・`validateRow(` は拾わない）。`$validated['x']` は変数なので拾わない
- メソッドの**宣言**（`function validate(`）は `->` / `::` が前に無いので拾わない
- **名前の解決**: ファイルの冒頭（波括弧の外）の `use` を読み、`use A\B\C;` と `use A\B\C as D;` を対応表にする
  （`use function`・`use const` は飛ばす）。完全な名前（`\Illuminate\…`）はそのまま、対応表にある短い名前は置き換え、
  どちらでもない短い名前は `namespace` を前に付ける（PHP と同じ規則）。`ValidationException` とみなすのは
  `Illuminate\Validation\ValidationException` に解決されたものだけ
- 波括弧の深さは `{`・`}` のほか、文字列の中の `{$`（`T_CURLY_OPEN`）・`${`（`T_DOLLAR_OPEN_CURLY_BRACES`）も数える
  （どちらも `}` で閉じる。数え漏らすと try の範囲がずれる）
- ⚠ 解析できない書き方（まとめた `use`・1 ファイルに `namespace` が 2 つ以上・`namespace X { … }` の形・
  波括弧の対応が取れない）に出会ったら、推測せずにそのファイルを「解析できない」として落とす
- ⚠ 拾いすぎたとき（`fails()` を確かめた後の `validated()`、別の物の `validate()`・`parent::validate()` など）は、
  検出を緩めずに例外リストへ理由つきで載せる（既存の走査と同じ方針）

### 4.4 新しい走査: 包んであるか（判定）

呼び出しごとに、次の **A か B** を満たせば「包んである」、どちらも満たさなければ「包んでいない」と数える。

**A. 囲む try の catch で包んでいる**

1. 呼び出しを囲む **try 本体**（`try` の直後の `{` と対応する `}` の間。catch や finally の本体は含まない）を、
   内側から外へ順にたどる
2. 各 try の catch を上から順に見て、`ValidationException` を受け止める**最初の** catch で判定を決める
   （その catch で終わり。外側の try は見ない。受け止める catch が 1 つも無ければ、さらに外側の try へ）
   - 受け止める型: `Illuminate\Validation\ValidationException`・`Exception`・`Throwable`（§4.3 と同じ規則で名前を解決する。
     `namespace` の中で `use` の無い `Exception` は別のクラスを指すので受け止めない）。`A | B` の catch は、どれか 1 つでも
     当たれば受け止める
3. その catch が次の**両方**を満たせば合格
   - 型に `Exception`・`Throwable` を含まない（総称の catch が先に受け止めるなら不合格。catch は上から順に、最初に当たったものが効く）
   - 本体（コメントを除く）が `throw $<その catch の変数>->redirectTo(…)…;` の **1 文だけ**。正確には、本体のトークンが
     `throw`・catch の変数・`->`・`redirectTo`・`(` で始まり、括弧の外にある最初の `;` で終わる（その後に何も無い）
     （`redirectTo` は大文字小文字を区別しない。`redirectTo(…)` の後に続く呼び出しは許す）
4. 変数の無い catch（`catch (ValidationException)`）は投げ直せないので不合格

**B. 投げる文そのものに `->redirectTo(` が続いている**

- 呼び出しが `throw` 文の中にあり、同じ文の中で呼び出しより後ろに `->redirectTo(` が続く
  （例: `throw ValidationException::withMessages([...])->redirectTo(route('…'));`・
  `throw (new ValidationException($v))->redirectTo(route('…'));`）

補足:
- 今の 7 か所はすべて A の形（§2.1）
- ⚠ 本体を 1 文に限るのは、条件次第で `throw $e;`（戻り先なし）と投げ直す書き方を合格にしないため
- ⚠ `redirectTo()` に渡す戻り先の中身はこの走査では見ない。`back()`・`url()->previous()`・今の URL は既存の走査が見つける（§4.7）
- 呼び出しの中にクロージャがあっても、字面で囲まれていれば囲まれているとみなす（§4.9 の死角）

### 4.5 新しい走査: FormRequest（Reflection）

- 列挙したファイルの相対パスからクラス名を作る（`app/` → `App\`、`/` → `\`、`.php` を外す）
- `ReflectionClass::getMethods(ReflectionMethod::IS_PUBLIC)` の各引数の型を見て、`Illuminate\Foundation\Http\FormRequest`
  の子クラスが 1 つでもあれば不合格（FormRequest の既定の戻り先はリファラー。§2.3）
- 型は `A`・`?A`・`A|B`・`A&B` のどれでも中の名前を 1 つずつ見る。組み込みの型（`int` など）は飛ばす
- 親クラス（`Illuminate\Routing\Controller`）から受け継ぐ public メソッドも見るが、クラスを型に持つ引数は無いので影響しない
- 今は 0 件。**例外リストは作らない**。使う必要が出たら、そのときにこの規則を見直す

### 4.6 新しい走査: 例外リストと報告

```php
/** @var array<string, array{0: int, 1: string}> 相対パス => [包んでいない呼び出しの件数, 理由] */
private const ALLOWED = [
    'app/Http/Controllers/Admin/CustomerImportController.php' => [
        1,
        '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
            . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
    ],
    'app/Http/Controllers/Tenant/AreaBuildingImportController.php' => [
        1,
        '確認は画面の中（SheetJS）で行い、確定の送信元は GET の取込の画面。'
            . 'リファラーへ戻ると取込の画面が開く（2026-09-24 実測 200）',
    ],
    'app/Http/Controllers/Zeal/SheetImportController.php' => [
        1,
        'updateUrls（PUT）の送信元は GET の URL 編集画面（editUrls が描く zeal/simulations/sheet-import/urls.blade.php）だけ。'
            . 'リファラーへ戻ると編集画面が GET で開く（2026-09-25 にルートとビューで確認）',
    ],
];
```

- ファイルごとに「包んでいない呼び出しの件数」が例外リストの件数と一致しなければ落とす（載っていないファイルは 0 件）。
  例外リストに載っているのにファイルが無い（古い項目）も落とす（既存の走査と同じ形）
- 失敗の文は、ファイル・行・呼び出しの形・**不合格の理由**を並べる
  （例: `:28 ->validate(（try の外）`・`（\Exception の catch が先に受け止める）`・
  `（catch の本体が throw $e->redirectTo(…) の 1 文でない）`・`（use の無い ValidationException を受けている）`）
- 走査の空振りに備え、見つけた投げる呼び出しの総数が **10 件**（実測）を下回ったら落とす

### 4.7 既存の走査に足す「今の URL」の検出

`ImportControllerReturnPathScanTest::returnsToReferer()` に次を足す。

| 足すもの | 拾う形 |
|---|---|
| 今の URL を作る呼び出し（条件を 1 つ新設）| `url()->current()`・`url()->full()`・`URL::current()`・`URL::full()`・`app('url')->current()` |
| リクエストから今の URL を読む呼び出し（今の条件を広げる）| 呼び出し元に `Request::`（ファサード）を足す。メソッドに `getUri`・`getRequestUri`・`path`・`decodedPath`・`getPathInfo` を足す（今は `url`・`fullUrl`・`fullUrlWithQuery`・`fullUrlWithoutQuery` の 4 つ）|

- `path()` 系を足すのは、相対パスも `UrlGenerator::to()` が今のホストの URL に組み直すため（§2.3）
- `Request::` の前は語の途中でないこと（`FormRequest::url(` には当てない）
- 取込のコントローラには、どれも今は 0 件。例外リスト（顧客 2 件・周辺ビル 2 件）の件数は変わらない
- 自己テスト:
  - 拾う見本: 条件の分かれ目ごとに 1 つ以上（§2.4 の 10 通り＋ `path()` 系 3 つ＋ `Request::` 1 つ）。
    見本の無い分かれ目は、消してもテストが緑のままになる（Bug #61 ③）
  - 拾わない見本: 名前は同じでも呼び出し元が違うもの（`$iterator->current()`・`Storage::path($p)`・
    `$request->file('csv')->path()`）
- docblock:
  - 「見えないもの」から入力チェックの項を外し、新しい走査を指す
  - 分担を書く: 新しい走査は包み方だけを見て、`redirectTo()` に渡す戻り先はこちらの走査が見る
  - 呼び出し元を変数に入れた形（`$req->url()`・`$this->request->url()`）は見えない、と書き足す

### 4.8 新しい走査の自己テスト

検出と判定の分かれ目をすべて見本で試す（見本の無い分かれ目は、消しても緑のまま。Bug #61 ③）。
見本は小さな PHP のソース（`namespace`・`use`・クラス・メソッド）を文字列で渡し、走査と同じ関数に通す。

- **投げる呼び出しの検出**: §4.3 の 7 形それぞれ＋大文字の `->Validate(`・`?->validate(`・`$this->validate(`。
  拾わない見本は `validateSales(`・`validateRow(`・`$validated['x']`・文字列やコメントの中の `->validate(`・
  `function validate(` の宣言・`use` の無い `new ValidationException`
- **包んであるとみなす見本**: 今の書き方／完全な名前で書いた catch（`\Illuminate\Validation\ValidationException`）／
  `as` の別名／`A | B` の catch／内側の try が別の例外しか受けない入れ子（外側の try が包む）／
  `namespace` の中で `use` の無い `Exception` の catch が先にある（別のクラスなので受け止めない）／
  `withMessages([...])->redirectTo(…)`／`(new ValidationException($v))->redirectTo(…)`／catch の中のコメント
- **包んでいないとみなす見本**: try が無い／`\Exception`・`\Throwable`・`use Exception;` のある `Exception` の catch が先にある／
  `A | \Exception` の catch／本体が `throw $e;`／本体が 2 文／`throw` の変数が catch の変数と違う／変数の無い catch／
  呼び出しが catch や finally の中／`use` の無い `ValidationException` の catch／`redirectTo` の無い
  `throw ValidationException::withMessages([...]);`
- **解析できない書き方**: まとめた `use`・`namespace` が 2 つ・`namespace X { … }` の形・波括弧の対応が取れない →
  「解析できない」として落ちる
- **波括弧の深さ**: 文字列の中の `{$…}` を挟んでも try の範囲がずれない（`T_CURLY_OPEN` を数え漏らす変異を捕まえる）
- **FormRequest**: 無名クラスに `EmailVerificationRequest` を `A`・`?A`・`A|B` の型で受けさせて見つかること、
  `Illuminate\Http\Request` だけなら見つからないこと
- **コメントとの行番号**: コメントの中の `->validate(` を数えず、本物の呼び出しを正しい行番号で報告する

### 4.9 見えないもの（新しい走査の docblock に書く）

- トレイト・親クラス・サービスに移した入力チェック（今は 0 件）
- `*ImportController.php` という名前でない取込（今は 2 本。§2.5 のとおり 405 の形ではない）
- try の中で作って、try の外で呼ばれるクロージャ（字面では包まれて見える）
- 見本に無い書き方（§4.3 の 7 形のほかの投げ方）
- 拾いすぎるもの: 呼び出し元のメソッドで包んだ形（メソッドをまたぐと見えない）→ 呼び出しのすぐ外で包む形に直すか、
  例外リストに載せる

### 4.10 文書に記録するもの

- `docs/RULES.md` Bug #64 の「⚠ **走査に見えない形がある**」の段落を、`ImportControllerValidationRedirectScanTest` が
  止める（包み方と FormRequest を全件分類で見る・見えないものは §4.9）という内容に書き換える。
  「今の URL」の検出を足したことも書く
- `docs/BACKLOG.md` の「CSV 取込の確認画面から断られると 405 になる件」の範囲外の 1 項目を対応済みにし、
  節を 1 つ足す（テストだけの変更・本番への反映は不要）
- `CLAUDE.md` の Laravel-specific quirks の User の行「取込は `ImportControllerReturnPathScanTest` が止める」を
  「取込は `ImportControllerReturnPathScanTest`（戻り先の書き方）と `ImportControllerValidationRedirectScanTest`
  （入力チェックの包み方）が止める」に直す
- この設計書と計画書

---

## 5. 検証

### 5.1 全件テスト

worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。
着手前は **2169 tests / 14567 assertions**。完了時にすべて緑であること。

### 5.2 変異テスト

先にコミットしてから、別の worktree（`git worktree add --detach`）に vendor を実体コピー（`cp -Rc`）して行う
（Bug #50。symlink は不可）。最初にカナリア（確実に赤くなる変異）を通して、隔離が効いていることを確かめる。
変異 1 つごとに、前後で作業ツリーが空であること・置き換えがちょうど 1 か所であること・`git diff --stat` で
当たったことを確かめ、全件を `--log-junit` で流し、**落ちたテストと理由の文言まで**記録する（Bug #44）。

- **本番のコードに当てる変異**（走査が止めるべきもの。一時的で、コミットはしない）:
  - 7 か所それぞれの try/catch を外す（7 通り）→ 新しい走査が、そのファイルと行を名指しして落ちる
  - catch を `\Exception`・`\Throwable` にする／前に `catch (\Exception $e) { throw $e; }` を挟む
  - 本体を `throw $e;` にする／本体に 1 文足す
  - `use Illuminate\Validation\ValidationException;` を消す
  - 戻り先を `url()->current()`・`$request->path()` にする → 既存の走査が落ちる
  - 例外リストの 3 か所のどれかを包む → 件数が合わずに落ちる
  - FormRequest を受けるメソッドを足す／包んでいない入力チェックを持つ取込のコントローラを新しく足す
- **テストに当てる変異**（検出を弱める）: 検出の条件を 1 つずつ消す・catch の順番を見ない・1 文の判定を外す・
  `use` を解決しない・`A|B` の型を見ない・既存の走査に足した条件を消す → 自己テストが落ちる
- 全部で 30〜40 通りの見込み。一覧と結果は計画書に書く

### 5.3 独立レビュー

実装の後に 1 回。指摘は実測してから直す。

---

## 6. やらないこと

- アプリ全体の安全網（入力チェックで断られたとき、リファラーが POST 専用の URL なら直前の GET の画面へ戻す。案 2）
- 実際に送って確かめる走査（§3 の 2・3）
- 包んでいない 3 か所を包み直す（本番のコードを変えない）
- FormRequest の例外リスト（今は 0 件）
- `*ImportController.php` という名前でない取込（今は 2 本で、405 の形ではない）
- BACKLOG の範囲外にあるほかの 2 件（ZEAL 会員の確定で DB の例外が出ると 500・顧客の取込は断られると確認画面の内容が消える）

---

## 7. 本番への反映

不要。変わるのはテストと文書だけで、`deploy.sh` は `tests/` と `docs/` を送らない。
main repo で `13.x` へ早送りするだけ（`origin/13.x` への push は利用者の指示があったとき）。
