# ほかの取込の二重送信を止める — 設計書

作成日: 2026-09-28
対象:
- コントローラ 6 本: `Admin/TenantImportController.php`・`Admin/MansionImportController.php`・`Admin/ZealMemberImportController.php`・
  `Housing/ScheduleImportController.php`・`Zeal/SheetImportController.php`・`Tenant/AreaBuildingImportController.php`
- 画面 6 つ: `admin/tenant-import/_preview.blade.php`・`admin/mansion-import/_preview.blade.php`・`admin/zeal-member-import/preview.blade.php`・
  `housing/properties/schedule-import.blade.php`・`zeal/simulations/sheet-import/preview.blade.php`・`tenant/area-buildings/import.blade.php`
- 新しい共通の部品: `resources/views/_partials/_submit_once.blade.php`

前例: 顧客CSVインポートの二重送信（docs/RULES.md Bug #67・設計書 `2026-09-27-customer-import-double-submit-design.md`）
　　　Bug #64（断るときの戻り先を取込の画面に固定する）・#65（`pageshow` で状態を戻す・`event.persisted` で絞らない）・
　　　Top trap #4（`x-data` は関数名で呼ぶ）・#5（`style` と `:style`）・#12（押せないボタンの `title`）・#13（全件分類）

---

## 1. 背景と依頼

Bug #67 で、顧客CSVインポートの確定を「確認画面 1 つにつき 1 回だけ」にした。そのとき範囲外に残した「ほかの取込の二重送信」を直す。

2026-09-28 に、ほかの取込の 16 経路で「同じ確認画面の確定を 2 回送ると二重になるか」を使い捨てのテストで測った（§2.1）。
5 経路で行が二重になり、1 経路で履歴だけが二重になる。残りの 10 経路は、重複の確認などのおかげで二重にはならないが、
2 回目にも成功の帯（「0件を登録しました」など）が出る。
顧客と決裁の取込を除くと、どの確定の画面にも、1 回だけ送らせる鍵も、送信中にボタンを押せなくする仕組みも無い。

対話で決まったこと（2026-09-28）:

| 論点 | 決定 |
|---|---|
| 範囲 | **案 A**: 16 経路すべてを顧客の取込と同じ形にする（確認画面 1 つにつき 1 回だけ送れる・送信中はボタンを押せない）。周辺ビルは確認画面が無いので、取込の画面を開いたときに 1 回分の鍵を出す |
| シート取込の古い確認画面 | **案 a**: 今回に含める。確定のときに作り直した反映の内容（どのセルをいくらにするか）がプレビューと違えば断る（§4.3）|
| 画面の二度押し止め | **案 1**: 共通の部品を 1 つ作り、6 つの確認画面から使う。顧客の取込の画面は変えない（§4.5）|
| 同じファイルを上げ直したときの二重 | 範囲外（案 C。別の作業。§6）|
| 断りの文言の「確かめる画面」 | タブごとに変える（§4.4）|
| 周辺ビルの「戻る」 | 送ったあとに絞って読み込み直す（§4.5）|
| シート取込の指紋に入れるもの | 書く行の `[category_id, new_amount]` と、どの試算表のどの月か。いまの値は入れない（§4.3）|
| 設計の 5 節 | ① サーバ側の鍵 ② 断りの文言と戻り先 ③ 画面の二度押し止め ④ シート取込の指紋 ⑤ テストと検証・範囲外。いずれも承認済み |

---

## 2. 調査で分かった事実

調べたのは `13.x` = `ccd687fe`。この worktree は `9e2df5b8` から切った。その間の差分は添付の名前の修正と記録だけで、
取込のコードには触れていない（`git diff --stat ccd687fe 9e2df5b8`）。行番号はどちらでも同じ。

### 2.1 16 経路の 2 回送り（実測）

同じ利用者で、画面が描いた確定のフォームを 1 回だけ分解して 2 回送った。
4 本の使い捨てのテストのどれでも、1 回目で取り込まれたことをテストの中で確かめ、流し直して同じ数字になることも確かめた。

| 取込 | 経路 | 主な表の件数（前→1回目→2回目）| 2回目の文言 | 判定 |
|---|---|---|---|---|
| テナント | 物件・区画・顧客 | どれも 0→2→2 | 「…インポート完了: 0件を登録しました」| 二重にならない |
| テナント | 契約 | 0→2→4 | 「契約インポート完了: 2件を登録しました」（1回目と同じ）| **二重** |
| テナント | 過去契約 | 0→2→4 | 「過去契約インポート完了: 契約 2件を登録」| **二重**（顧客の自動作成は1回目だけ）|
| 賃貸マンション | 物件・部屋・駐車場・入居者 | どれも 0→2→2 | 「…インポート完了: 0件を登録しました」| 二重にならない |
| 賃貸マンション | 部屋契約 | 0→2→4 | 1回目と同じ「2件を登録しました」| **二重**（契約中・解約済みとも）|
| 賃貸マンション | 駐車場契約 | 0→2→4 | 1回目と同じ「2件を登録しました」| **二重** |
| ZEAL 会員 | — | 0→5→5 | 「登録 0件 / スキップ 5件…」| 二重にならない（氏名＋入会日で飛ばす）|
| 工程表（建売）| — | 0→65→65 | 「既存の 65 件を入れ替えて 65 件を登録」| 二重にならない（丸ごと入れ替え・工程の id は作り直し）|
| 周辺ビル | ビル＋調査 | 調査回 0→4→4 | 「調査追加 0 件 / 同一年月のためスキップ 4 件」| 二重にならない |
| 周辺ビル | テナント明細 | 0→3→6 | 「テナント登録 3 件…現況テナント数: アルファビル 4 件」| **二重** |
| ZEAL シート取込 | — | セル 2→6→6・履歴（`zeal_sheet_imports`）0→2→4 | 「取り込みました (0 セル更新)」| セルは二重にならない・**履歴だけ二重** |

- 同じ CSV を上げ直しても、契約系の 4 経路は取り込めてしまう（テナントの過去契約は注意すら出ない）。これは鍵では止まらない（範囲外。§6）

### 2.2 二重になる理由（コードの読み取り）

- テナントの契約: 二重契約の確認が警告だけ（`TenantImportController.php:748-754`）
- テナントの過去契約: 期間の重なりの確認が、契約中の契約しか見ない。これも警告だけ（同 1014-1026）
- 賃貸マンションの部屋契約・駐車場契約: 契約中の行に注意を出すだけで、確定は注意を見ずに追加する
  （`MansionImportController.php` の部屋契約 936-944・書き込み 994-1025 ／ 駐車場契約 1200-1208・書き込み 1236-1266）
- 周辺ビルのテナント明細: 既存の行と突き合わせる手がかりが無い作り（`AreaBuildingImportController.php:291-298`・注記 315-318）。
  画面にも「同じファイルを 2 回取り込むと行が二重になります」と書いてある（`tenant/area-buildings/import.blade.php:57`）
- シート取込: 値が同じなら書かない（`SheetImportController.php:347` の `will_update` と 158 行の飛ばし）が、
  履歴は毎回作る（同 178・189 行の `ZealSheetImport::create`）

### 2.3 いまの確定の入口と戻り先

| 取込 | 確認画面を出す所 | 確定の入口 | いまの断りの戻り先 |
|---|---|---|---|
| テナント（5 タブ）| 各タブのメソッドの `view()`（238・451・590・803・1064 行）| 各タブのメソッドが最初に呼ぶ `loadCsv()` の確定の分岐（1260 行）。5 タブとも `loadCsv()` の差し戻しをそのまま返す（5/5 を数えた）| 取込の画面の同じタブ（`route('admin.tenant-import', ['tab' => $tab])`。Bug #64）|
| 賃貸マンション（6 タブ）| 同じく `view()`（298・482・643・772・981・1223 行）| 同じく `loadCsv()` の確定の分岐（1387 行。6/6）| 同じタブ（`route('admin.mansion-import', ['selected_tab' => $tab])`）|
| ZEAL 会員 | `preview()`（103 行の `view()`）| `execute()`（118 行）| 取込の画面（`route('admin.zeal.member-import')`）|
| 工程表（建売）| `preview()`（75 行の `view()`）| `execute()`（87 行）| その物件の取込の画面（`route('housing.properties.schedule-import.form', $property)`）。いまの差し戻しは `withErrors` で画面の中の枠に出る |
| シート取込 | `preview()`（113 行の `view()`）| `apply()`（127 行）| 試算表の画面（`route('zeal.simulations.show', $simulation)`）|
| 周辺ビル（2 種）| 確認画面は無い。確かめるのは画面の中の SheetJS で、取込の画面は GET の `form()`（74 行）| `execute()`（79 行）| 成功は周辺ビル調査の一覧。行の断りは `back()`（送信元は GET の取込の画面なので 405 にならない。走査テストの例外リストの 1 か所）|

- テナント・賃貸マンションのタブのキー: テナントは `property`・`unit`・`customer`・`contract`・`past-contract`、
  賃貸マンションは `property`・`room`・`parking`・`tenant`・`room_contract`・`parking_contract`

### 2.4 手本と部品

- **`OneTimeAction`**（`app/Support/OneTimeAction.php`）:
  - `issue()` は 40 文字の鍵を作るだけで、発行を記録しない。新しい値なら、どんな値でも 1 回は通る（守るのは「同じ鍵の 2 回目」だけ）
  - `claimFrom($request, $field)` は hidden を読み、文字列でなければ断る（配列でも 500 にならない）
  - 使った鍵はキャッシュに覚えておく（`Cache::add`）。覚えておく時間は `config('approval.guide_token_ttl_hours')`（既定 12 時間）。
    本番のキャッシュは file ドライバで、`FileStore::add()` が排他ロックを取ってから書くので、ほぼ同時の 2 回でも片方しか通らない
  - テストのキャッシュは `array`（`phpunit.xml:37`）で、1 つのテストの中では覚えている
- **顧客の取込**（`CustomerImportController.php`）: プレビューで鍵を発行し（171 行）、確定では部署の入力チェックの直後・
  CSV を読み直す前に使う（68-71 行）。断りの文言は「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。
  取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。」
- **1 か所だけで定義する部品の前例**: `resources/views/_partials/_schedule_gantt_style.blade.php`（`@once` と `@push('styles')` で囲む）
- **レイアウト**（`resources/views/layouts/app.blade.php`）:
  - `@stack('scripts')` は 182 行で、Alpine を読む `@vite`（8 行・module なので defer）より後。
    ここへ積んだ `<script>` は読み込みの途中で動くので、関数は Alpine の起動より前にできている
  - `session('error')` は 83-90 行の赤帯が描く。戻り先の 6 画面はどれもこのレイアウトを使い、自分では `session('error')` を描かない（grep で確かめた）

### 2.5 画面の事実

- 6 つの確定のボタンは、どれも `name` を持たない
- テナント・賃貸マンション・ZEAL 会員・シート取込のボタンは、`style` に `cursor: pointer` を書いている。
  工程表と周辺ビルは Tailwind のクラスで、カーソルの指定は無い。周辺ビルは `disabled:opacity-50` を持つ
- 入れ子: テナント・賃貸マンションのフォームは `tenantImportTabs()` / `mansionImportTabs()` の中、周辺ビルのフォームは
  `areaImportForm()`（15 行）の中にある。周辺ビルの hidden は親の値（`kind`・`surveyedMonth`・`payload()`）を `:value` で入れている。
  `submitting`・`onSubmit`・`onPageShow` という名前は、これらの画面のどこにも無い（grep で確かめた）
- `cursor-pointer`・`disabled:cursor-not-allowed`・`disabled:opacity-60` は、顧客の取込でビルドに入っている（2026-09-28 に本番へ出た）
- 一覧が何も選ばないときに出すもの:
  - テナントの「契約一覧」は「契約中」だけ（`Tenant/ContractController.php:71` の `input('status', 'active')`）＝ 取り込んだ過去契約は 1 件も出ない
  - 賃貸マンションの部屋契約・駐車場契約の一覧は「すべて」（年度も、指定が無ければ絞らない）
  - ZEAL の「会員管理」は「在籍中」（`Zeal/MemberController.php:36`）
- サイドバーの表記（`layouts/partials/sidebar.blade.php`）: テナント管理「物件一覧」「部屋一覧」「契約一覧」「顧客一覧」「周辺ビル調査」、
  賃貸マンション「物件一覧」「入居者管理」「部屋契約一覧」「駐車場契約一覧」、ZEAL「会員管理」「経営試算表」。
  賃貸マンションの物件の詳細は「部屋一覧（N戸）」と「駐車場（N台）」を出す
- シート取込は、試算表の画面の「本部 Sheet を取り込む」から対象の月を選んで「プレビューを表示」で始まる。確定のボタンは「試算表に反映する」

### 2.6 既存のテストとの関係

- `LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim()` は、コメントを落としたうえで
  `claimFrom(` の呼び出しが 6 か所以上あることを見る（`tests/Feature/Approval/LoginGuideTest.php:414-418`）
- 取込の走査テスト 2 本（`ImportControllerReturnPathScanTest`・`ImportControllerValidationRedirectScanTest`）は、
  新しい断りがどれも `route()` の転送で、`back()` も包んでいない入力チェックも増えないので、変えずに通る見込み（計画で測る）
- 確定を手で組んで送っている既存のテスト（2026-09-28 に数えた）:
  - 周辺ビル（`tests/Feature/Tenant/AreaBuildingImportTest.php`）: `importBuildings()`（17 回呼ばれる）と `importTenants()`（9 回）の 2 つの関数、
    それとは別に直接送る 10 か所（137・288・443・465・501・540・722・739・753・761 行）。権限の検査の 1 か所（107 行）は門番で止まるので影響しない
  - ZEAL 会員（`tests/Feature/Admin/ZealMemberImportControllerTest.php`）: 確定へ直接送る 4 か所（117・193・232・296 行）
  - 工程表（`tests/Feature/Housing/HousingConstructionStartDateTest.php`）: `importRows()`（5 回呼ばれる）と、直接送る 1 か所（246 行）
  - ⚠ 節 5 では周辺ビルを「28 か所」、ZEAL 会員を「5 本」と伝えたが、関数の定義の行や別の経路を数えていた。上が正しい数
- シート取込のプレビューと確定を通る Feature テストは 1 本も無い（名前が出るのは URL の保存の検査と走査テストだけ）
- テスト用スキーマ（`tests/Concerns/CreatesZealSimulationSchema.php`）に、正本の DDL にあるものが足りない:
  `zeal_simulations.sales_sheet_url`・`expense_sheet_url`（`database/sql/alter_zeal_simulations_add_sheet_urls.sql`）、
  `zeal_sheet_imports` の表（`create_zeal_sheet_imports_table.sql`）、一意の索引 3 本
  （`uq_zeal_sim_cat_code`・`uq_zeal_sim_fiscal_year`・`uq_zeal_sim_val`。`create_zeal_simulation_tables.sql:27・43・60`）。
  このスキーマは `MonthEndOverflowTest`・`SimulationValidationFeedbackTest` も使う
- `ZealSheetClient`（`app/Support/ZealSheetClient.php`）は `final` ではなく、`fetchCsv()` は public（39 行）
- `approval-phase2a`（別の会話が作業中）の差分は、この作業で触るファイルのどれにも触れていない（`git diff --name-only 13.x...approval-phase2a`）

---

## 3. 方針

| 論点 | 採った案 | 理由 |
|---|---|---|
| 範囲 | 16 経路すべて（案 A）| 二重にならない経路でも、2 回目に成功の帯が出て取り込み直したように見える。どの画面も同じ振る舞いにそろえる |
| シート取込の古い確認画面 | 指紋で断る（案 a）| 確定は本部 Sheet を読み直して作り直した内容を書くので、鍵だけでは「見せていない値を書く」余地が残る |
| 画面の二度押し止め | 共通の部品を 1 つ（案 1）| 6 か所に同じ JS を書くと、直すたびにずれる（Bug #41 と同じ型）|
| 同じファイルの上げ直し | 範囲外（案 C）| 業務の判断が要る（上げ直しは正当な操作でもある）|

---

## 4. 設計

### 4.1 ファイル構成

| ファイル | 変更 |
|---|---|
| `app/Http/Controllers/Admin/TenantImportController.php` | 5 タブのプレビューで鍵を発行・`loadCsv()` の確定の分岐の最初で使う・タブごとの確かめる画面（§4.2・§4.4）|
| `app/Http/Controllers/Admin/MansionImportController.php` | 同じ（6 タブ）|
| `app/Http/Controllers/Admin/ZealMemberImportController.php` | `preview()` で発行・`execute()` の最初で使う |
| `app/Http/Controllers/Housing/ScheduleImportController.php` | `preview()` で発行・`execute()` の最初で使う |
| `app/Http/Controllers/Zeal/SheetImportController.php` | `preview()` で鍵と指紋を渡す・`apply()` で鍵を使ってから指紋を比べる（§4.3）|
| `app/Http/Controllers/Tenant/AreaBuildingImportController.php` | `form()` で発行・`execute()` の最初で使う |
| `resources/views/_partials/_submit_once.blade.php`（新規）| 二度押し止めの関数 `submitOnce()` の唯一の定義（§4.5）|
| 確定のフォームを持つ画面 6 つ | hidden の `import_token`（シート取込は `plan_digest` も）・二度押し止め（§4.5）|
| `app/Support/OneTimeAction.php` | docblock の「使っている所」を書き直す（処理は変えない）|
| `tests/…` | §5 |
| `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・実装計画 | 記録（§4.6）|

**本番の DB 変更・新しい PHP クラス・依存の変更は無い**（`composer dump-autoload` は要らない）。
CSS も変わらない見込み（使うクラスはどれもビルドに入っている。§2.5）。

### 4.2 サーバ側の鍵（節 1）

部品は顧客の取込と同じ `OneTimeAction`。hidden の名前も同じ `import_token` で、`OneTimeAction::claimFrom($request, 'import_token')` で使う。
覚えておく時間も同じ設定（`approval.guide_token_ttl_hours`・12 時間）を使い回す。

| 取込 | 鍵を出す所 | 鍵を使う所（確定の入口）| 断ったときの戻り先 |
|---|---|---|---|
| テナント（5 タブ）| 各タブのプレビューの `view()`（5 か所）| `loadCsv()` の確定の分岐の最初（1 か所で全タブに効く）| 取込の画面の同じタブ |
| 賃貸マンション（6 タブ）| 各タブのプレビューの `view()`（6 か所）| `loadCsv()` の確定の分岐の最初 | 同じタブ |
| ZEAL 会員 | `preview()` | `execute()` の最初 | 取込の画面 |
| 工程表（建売）| `preview()` | `execute()` の最初 | その物件の取込の画面 |
| シート取込 | `preview()` | `apply()` の最初（指紋はそのあと）| 試算表の画面 |
| 周辺ビル（2 種）| 取込の画面を開いたとき（`form()`）| `execute()` の最初 | 取込の画面（開き直すので新しい鍵が出る）|

決まりごと:

1. 鍵は確定の入口で、CSV の読み直しや行の検査より前に使う。2 回目がどの検査にも、どの別の文言にも届かないようにするため
   （顧客の取込で、0 件の歯止めより後ろに置くと 2 回目が別の案内に着いた教訓。Bug #67）
2. 使えなければ、何も書き込まずに断る（文言は §4.4）
3. 1 回目が書き込みの途中で失敗しても、鍵は使用済みのまま。ファイルを上げ直せば新しい鍵で取り込める
4. シート取込は、鍵を使ってから指紋を比べる。逆だと、ダブルクリックの 2 回目が、1 回目で値が書かれたあとなので
   「内容が変わりました」という別の理由で断られる

- デプロイの前に開いた確認画面には鍵が無いので、デプロイのあとにそこから送ると断られる（上げ直せば通る）
- 画面を経由しない POST も、新しい鍵を付ければ通る（§2.4。鍵は権限の代わりではなく、同じ画面の 2 回目を止めるもの）

### 4.3 シート取込の指紋（節 4）

**いまの問題**: `apply()` は本部 Sheet を読み直して反映の内容を作り直し、そのまま書く（127-206 行）。
プレビューのあとで本部 Sheet か試算表の値が変わっていると、確認画面で見せていない値を書いてしまう。

**指紋**: どの試算表の・どの月に・どの項目を・いくらにするかを 1 つの文字列にまとめる。
プレビューと確定の両方が、新しい private メソッド 1 つだけで作る。

```php
private function planDigest(ZealSimulation $simulation, string $yearMonth, array $plan): string
{
    $writes = [];
    foreach ($plan as $row) {
        if ($row['will_update']) {
            $writes[] = [$row['category_id'], $row['new_amount']];
        }
    }

    return hash('sha256', json_encode([$simulation->id, $yearMonth, $writes]));
}
```

- 並びは `buildApplyPlan()` が作る順（売上のあとに `ZealExpenseMapper::WRITABLE_CODES` の順）で、決まっている
- 確認画面のフォーム（`preview.blade.php:229-236`。いまは `year_month` だけを運ぶ）に、hidden の `plan_digest` と `import_token` を足す

**`apply()` の順番**:

1. 鍵を使う（§4.2）
2. 対象月を確かめる（いまのまま）
3. 本部 Sheet を読み直す（いまのまま）
4. 売上と経費のどちらも読めなければ、いまの断りを出す
5. 反映の内容を作り直す
6. 指紋を比べる。`$given = $request->input('plan_digest');` を `is_string()` で確かめてから、`hash_equals($計算した指紋, $given)` で比べる
   （配列が送られると `hash_equals` が TypeError で 500 になるので、`claimFrom()` と同じ守り方をする）。
   違えば、セルにも履歴にも何も書かずに試算表の画面へ戻し、
   「プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。」と出す
7. 書く（いまのまま）

**断る・通すの結果**:

| プレビューのあとに変わったもの | 結果 |
|---|---|
| 書く行の金額（本部 Sheet の値）| 断る |
| 書く行の組（手で直したセルが「書かない行」から「書く行」になった、またはその逆）| 断る |
| 別のタブで同じ月を先に反映した | 断る（こちらの書く行が無くなる。順に届く 2 つのタブの重複もこれで止まる）|
| 書く行のセルの、いまの値だけ（書く値は同じ）| 通す（プレビューで見せた値を書く）|
| 書かない行や、反映に関係の無いセル | 通す |
| 取込の履歴の `raw_csv` | 確定のときに読んだ中身で残す |

- 指紋は古い画面を見分けるためのもので、改ざんを防ぐ署名ではない。書き換えても、本人がもう一度プレビューするのと
  同じことしかできないので、HMAC にはしない
- いまの値も指紋に入れる案は採らない。入れると表の 4 行目も断るが、プレビューし直しても最後に書く値は同じで、
  決めたこと（「どのセルをいくらにするか」）に合わない

### 4.4 断りの文言と戻り先（節 2）

**文言の形**は顧客の取込と同じ 3 つの文。出し方は `->with('error', …)` で、レイアウトの赤帯（`layouts/app.blade.php:83-90`）が出す。

> 〈前半〉（すでに送信したか、画面が古くなっています）。取り込まれたかは〈確かめる画面〉で確かめられます。取り込み直すときは、〈やり直し方〉。

- 前半: CSV の取込と工程表は「この確認画面からは取り込めません」、周辺ビルは「この取込画面からは取り込めません」（確認画面が無いため）、
  シート取込は「この確認画面からは反映できません」（ボタンが「試算表に反映する」なので、後ろの 2 つの文も「反映された」「反映し直す」に言い換える）
- 工程表のいまの差し戻し（`withErrors`）は画面の中の枠に出るが、鍵の断りは 6 つとも赤帯にそろえる

**取込ごとの全文**（テストはこの全文で見る）。表の「…」は、1 行目（テナントの物件）と同じ前後の文:

| 取込 | 経路 | 断りの全文 |
|---|---|---|
| テナント | 物件 | この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。|
| | 区画 | …取り込まれたかは「部屋一覧」で確かめられます。… |
| | 顧客 | …取り込まれたかは「顧客一覧」で確かめられます。… |
| | 契約 | …取り込まれたかは「契約一覧」で確かめられます。… |
| | 過去契約 | …取り込まれたかは「契約一覧」でステータスを「解約済み」にして確かめられます。… |
| 賃貸マンション | 物件 | …取り込まれたかは「物件一覧」で確かめられます。… |
| | 部屋・駐車場 | …取り込まれたかは「物件一覧」から開く物件の詳細で確かめられます。… |
| | 入居者 | …取り込まれたかは「入居者管理」で確かめられます。… |
| | 部屋契約 | …取り込まれたかは「部屋契約一覧」で確かめられます。… |
| | 駐車場契約 | …取り込まれたかは「駐車場契約一覧」で確かめられます。… |
| ZEAL 会員 | — | …取り込まれたかは「会員管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。|
| 工程表（建売）| — | この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは、この物件の詳細の「工程表」で確かめられます。取り込み直すときは、ファイルを選び直してください。|
| 周辺ビル | 2 種 | この取込画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「周辺ビル調査」で確かめられます。取り込み直すときは、ファイルを選び直してください。|
| シート取込 | — | この確認画面からは反映できません（すでに送信したか、画面が古くなっています）。反映されたかは、この試算表で確かめられます。反映し直すときは、「本部 Sheet を取り込む」からもう一度プレビューしてください。|

- テナント・賃貸マンションは、タブのキーから「確かめられます」の前の句を引く小さな表を、それぞれのコントローラに 1 つずつ置く
  （`loadCsv()` はもう `$tab` を受け取っている）
- タブごとに変える理由: テナントの「契約一覧」は何も選ばないと「契約中」しか出さない（§2.5）。「契約一覧で確かめられます」だけでは
  過去契約が見えず「入っていない」と読んで上げ直しやすいうえ、過去契約は上げ直しても注意が出ないまま二重になる（§2.1）
- 絞り込みの案内を付けるのは過去契約だけ。賃貸マンションの契約一覧は何も選ばなければ「すべて」を出し、
  ZEAL の会員管理は「在籍中」を出すが、取り込んだ在籍中の会員が見えれば確かめられる
- シート取込の指紋が違うときの文言は §4.3

### 4.5 画面の二度押し止め（節 3）

**共通の部品** `resources/views/_partials/_submit_once.blade.php` に、`function submitOnce(options)` を 1 か所だけで定義する。
前例（`_schedule_gantt_style.blade.php`）と同じく `@once` と `@push('scripts')` で囲む。

```blade
{{-- 説明はここ（Blade のコメント）に書く。<script> の中の // や /* */ に @ で始まる語や <x- を書くと、
     Blade が展開して壊す（Bug #30） --}}
@once
    @push('scripts')
        <script>
        function submitOnce(options) {
            var reloadOnReturn = !!(options && options.reloadOnReturn);
            return {
                submitting: false,
                onSubmit: function (event) {
                    if (this.submitting) {
                        event.preventDefault();
                        return;
                    }
                    this.submitting = true;
                },
                onPageShow: function (event) {
                    if (reloadOnReturn && (event.persisted ? this.submitting : submitOnceNavigationType() === 'back_forward')) {
                        window.location.reload();
                        return;
                    }
                    this.submitting = false;
                }
            };
        }
        function submitOnceNavigationType() {
            var entries = window.performance && window.performance.getEntriesByType
                ? window.performance.getEntriesByType('navigation') : [];
            return entries.length > 0 ? entries[0].type : '';
        }
        </script>
    @endpush
@endonce
```

（形の見本。書き方の細部は計画で決める）

| 場面 | 動き |
|---|---|
| 1 回目の送信 | そのまま送り、`submitting` を立てる |
| 2 回目以降 | 送信を取り消す（`preventDefault`）|
| 送信中 | ボタンを `:disabled` にし、横の `role="status"` に「取り込んでいます…」（シート取込は「反映しています…」）を出す |
| `pageshow`（5 つの確認画面）| 印を下ろす（`persisted` で絞らない。Bug #65）。Chromium は POST の確認画面を bfcache に入れず控えから描き直し、そこから押してもサーバの鍵が断る（顧客の取込で実測）|
| `pageshow`（周辺ビル。`submitOnce({ reloadOnReturn: true })`）| 次の 2 つのときだけ読み込み直して、新しい鍵にする。① bfcache から戻り、かつ送ったあと（`submitting` が立っている）② bfcache を使わずに「戻る」で表示した（ナビゲーションの種類が `back_forward`）。それ以外は印を下ろすだけ |

- 周辺ビルで絞る理由: 作業の途中で別の画面へ移って「戻る」で戻ったときは、鍵がまだ使える。このときは読み込んだファイルと
  列の対応づけを残したいので、読み込み直さない。② のときは Alpine の状態がもう消えているので、読み込み直しても失うものが無い
- 読み込み直したあとはナビゲーションの種類が `reload` になるので、読み込み直しを繰り返さない。GET の画面なので、送信のやり直しも起きない
- bfcache を使うかどうかは未実測（§5.8 で測る）

**6 つのフォームに書くこと**:

```blade
@include('_partials._submit_once')
<form method="POST" action="…（いまのまま）…" x-data="submitOnce()"
      x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
    @csrf
    …（いまの hidden）…
    <input type="hidden" name="import_token" value="{{ $importToken }}">
    <button type="submit" :disabled="submitting"
            class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
            style="…（いまの style から cursor を抜いたもの）…">…（いまの文言）…</button>
    <span role="status" x-text="submitting ? '取り込んでいます…' : ''"
          style="display: inline-block; …"></span>
</form>
```

- ボタンの `style` にある `cursor: pointer` は消す。`style` はクラスより強いので、残すと送信中もカーソルが変わらない
- ボタンに `name` を付けない。送信中に押せなくすると、そのボタンは送る中身から落ちる（いまの 6 つにも付いていない）
- 状態の文字は `inline-block`（375px で文字の途中で折り返さず、まとまって次の行へ移る。顧客の取込で実測）。
  `role="status"` の要素はいつも置いて、文字だけ変える
- ボタンの文言は変えない
- 部品の `@include` は、フォームと同じ `@if` の中に置く（フォームが出ない画面には関数も出さない）

| 画面 | 入れ子 | その画面だけのこと |
|---|---|---|
| テナント・賃貸マンションの `_preview` | `tenantImportTabs()` / `mansionImportTabs()` の中 | — |
| ZEAL 会員の `preview` | なし | ボタンは「キャンセル」と並ぶ flex の中 |
| 工程表の `schedule-import` | なし | ボタンは Tailwind のクラスだけ（いまはカーソルの指定が無い）|
| シート取込の `preview` | なし | 状態の文字は「反映しています…」。フォームは `display:inline` |
| 周辺ビルの `import` | `areaImportForm()` の中 | `x-data="submitOnce({ reloadOnReturn: true })"`。ボタンは `:disabled="submitting \|\| submitBlockedReason() !== null"`。薄さはいまの `disabled:opacity-50` のまま。押せない理由の `title` は、いまのままボタンを包む `span` に置く（Top trap #12）。hidden の `payload()` など親の値は、入れ子からも読めるはず（§5.8 で確かめる）|

- JS が動かないとき、Alpine の起動前に押されたときは画面の歯止めが効かないが、サーバの鍵が 2 回目を断る
- 顧客の取込の画面（独自の `csvImport()`）は変えない

### 4.6 文書に記録するもの

- `docs/RULES.md`: 新しい Bug（2026-09-28 の時点で最後は #68 なので #69 の見込み。**書く直前に最新の `13.x` で数え直す**。
  並行する会話がぶつかることがある）— 症状（§2.1）・原因（§2.2）・直し方・測って分かったこと
- `CLAUDE.md`: 「全 N 件の詳細バグカタログ」の件数
- `docs/BACKLOG.md`: 新しい節。「顧客CSVインポートの二重送信を止める」の範囲外の「ほかの取込…の二重送信」の行に、対応した日と節を書き足す
- `OneTimeAction.php` の docblock（使っている所）・6 本のコントローラの docblock
- 実装計画（`docs/superpowers/plans/2026-09-28-import-double-submit.md` の見込み）に実測の記録

---

## 5. 検証（節 5）

### 5.1 Feature テスト（16 経路）

**同じフォームを 2 回送る**（16 経路すべて）:

- 画面が描いた確定のフォームを 1 回だけ分解し、同じ利用者で 2 回送る
  - ⚠ `SubmitsImportPreview::confirm()` は、呼ぶたびに新しい利用者を作ってプレビューからやり直すので使わない
  - テナント・賃貸マンションは `preview()` と `parseImportForm()`、ほかは `ParsesForms::parseForm()` を `action="…"` まで含めた文字で探して使う
  - 周辺ビルは取込の画面を GET して分解し、Alpine が入れる 3 つ（`kind`・`surveyed_month`・`rows`）だけを手で埋める（いまある往復のテストと同じ形）
- 1 回目: 取り込まれたことを件数で確かめる（これが無いと、2 回目の「書き込み 0」がいつでも成り立ち、測れていないことになる）
- 2 回目:
  - 書き込みが 0。件数に加えて、2 回目の要求のあいだの INSERT / UPDATE / DELETE を `DB::listen` で数えて 0（工程表の入れ替えや、シート取込の履歴も捕まえるため）
  - 断りの文言を、役割（`error` のフラッシュに全文が入る）と表示（戻り先を GET して赤帯の中に全文が出る）に分けて見る。
    表示を見るテストの中では `assertSessionHas*` を使わない（Bug #49）
  - 戻り先。`Location` を `assertSame` で見る
- テナント・賃貸マンションのタブは、コントローラの public な `execute{X}` メソッドを機械的に列挙し、メソッド名ごとに持つテストの表と突き合わせる
  （新しいタブが増えたら落ちる。§4.4 の表にタブが足りないと、そのタブの断りが 500 になるのを防ぐ。Top trap #13。**設計書を書くときに足した**）。
  ⚠ タブのキーからは列挙しない。区切りの文字がそろっていない（テナントは `past-contract`、賃貸マンションは `room_contract`）

**同じファイルを上げ直すと、新しい鍵で取り込める**（取込ごとに 1 本）:
別のファイルで 2 回プレビューする形だと、鍵をファイルの中身から作る書き換え（上げ直しても同じ鍵になり、12 時間断られる）を見逃す
（2026-09-27 のレビューで実測）。同じファイルで確かめる。周辺ビルは、同じ取込の画面を 2 回開くと鍵が変わることを見る。

**使えない鍵 3 通り**（無い・空・配列）: 500 にならず、何も書かない。取込ごとにデータプロバイダで回す。

### 5.2 シート取込（初めての Feature テスト）

- 準備:
  - テスト用スキーマに、正本の DDL から列・表・一意の索引 3 本を足す（§2.6）。足したあと全件で流し、このスキーマを使う既存のテストが落ちないか確かめる
  - `ZealSheetClient` を、決まった CSV を返す無名の子クラスでコンテナの中身ごと差し替える（プレビューと確定の間で CSV を変えられるようにする）。
    `Http::preventStrayRequests()` を掛けて、外へ通信しないことも固める
- 往復: プレビュー → 確認画面のフォームを分解して送る → セルが変わり、履歴が売上・経費の 2 行できる
- 2 回送る: 2 回目は鍵の断り（「内容が変わりました」ではない）・セルも履歴も増えない
- 指紋: §4.3 の表の 6 場面を 1 つずつ。`plan_digest` が無い・配列のときも断り、500 にならない

### 5.3 既存のテストの直し方

- 画面が描いたフォームを分解して送るテストは、鍵の hidden も一緒に運ぶので、そのまま通る見込み
- 確定を手で組んで送るテスト（§2.6 の数）は鍵が無いので断られるようになる。直し方:
  - 周辺ビルの `importBuildings()` と `importTenants()` は、取込の画面を GET して、画面が描いた鍵を使う形に直す（画面が鍵を描くことも一緒に固まる）
  - それ以外は、1 送信ごとに新しい鍵を足す（`OneTimeAction::issue()`。発行を記録しないので、新しい値なら 1 回は通る）
- ⚠ 戻り先が同じで件数 0 しか見ていないテストは、鍵に断られても緑のままで、狙った経路を通らなくなる。
  確定へ送る既存のテストを全件洗い出し、鍵を足したうえで、狙った文言まで見ているかを確かめる
- `LoginGuideTest` の `claimFrom()` の件数の下限を 6 から 12 に上げる（`:414-418`。足したら下限も上げる。Bug #67 のレビューの教訓）

### 5.4 画面の構造と部品の実駆動

- 構造（6 つのフォーム。どれも**その要素に**付いていること。Bug #47）: hidden の `import_token`（シート取込は `plan_digest` も）、
  フォームの `x-data="submitOnce(…)"`・`x-on:submit="onSubmit($event)"`・`x-on:pageshow.window="onPageShow($event)"`、
  ボタンの `:disabled`（`submitting` を含む）と 3 つのクラス（周辺ビルは `disabled:opacity-50`）、`style` に cursor が無い、ボタンに `name` が無い、
  `role="status"` とその文字・`inline-block`
- 全件分類: `name="import_token"` を描く Blade を機械的に列挙し、`submitOnce(` を使っていなければ落とす。
  例外は理由つきの顧客の取込（独自の `csvImport()`）だけ
- 部品を node の vm で動かす（手本は `CustomerImportTest::csvImportInNode()`。画面が描いた `<script>` をそのまま読み込む）:
  1 回目は通し、2 回目は取り消す。`pageshow` で印を下ろす。
  `reloadOnReturn` の 4 場面（送ったあと bfcache で戻る／送らずに bfcache で戻る／`back_forward`／`reload`・`navigate`）と、読み込み直しを繰り返さないこと
- 部品の定義がページに 1 回だけ出ること（`@once`）

### 5.5 コントローラの全件分類

`ScansImportControllers` の列挙（`*ImportController.php`・8 本）を共用し、コメントを落としてから、
全部が `OneTimeAction::claimFrom(` を呼ぶことを見る。例外リストはいまは空で、足すときは理由をつける。

### 5.6 変異テスト

Bug #44 の手順（先にコミット・毎回 `git status --porcelain` が空・`git diff --stat` で当たったことを確かめる・落ちた理由の文言まで照らし合わせる）。
一覧は計画で決める。例: 取込ごとに鍵を外す ／ 鍵を読み直しや検査の後ろへ動かす ／ シート取込で指紋を鍵より先に比べる ／ `is_string` を外す ／
指紋に入れるものを変える ／ タブの表から 1 つ抜く ／ `@once` を外す ／ `persisted` で絞る ／ 周辺ビルの絞り込みを外す ／
ボタンに `name` を付ける ／ `style` に cursor を戻す ／ 部品の `@include` を消す、など。

### 5.7 全件テストとビュー

- 全件テスト（worktree で `APP_KEY` を環境変数で渡す）
- コンパイル済みビューの `php -l`（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

### 5.8 ローカルの実ブラウザ（使い捨て SQLite ＋ `artisan serve` ＋ Playwright。画面が見えている状態）

- ダブルクリックで確定の POST が 1 回だけになる
- 確定の応答を遅らせて、送信中の見た目（押せない・カーソル・薄さ・「取り込んでいます…」）を見る
- 完了のあと「戻る」で確認画面へ戻り、押すと断られる
- 周辺ビル: 送ったあと「戻る」で読み込み直して新しい鍵になる（Chromium がこの GET の画面を bfcache に入れるかも測る）。
  入れ子から `payload()` などの親の値が読める
- 375px で状態の文字が割れない・横スクロールが無い・コンソールのエラーが 0

### 5.9 独立レビュー

実装のあとに 1 回。

---

## 6. やらないこと（BACKLOG に書く）

- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C）
- まだ送っていない古い確認画面で、取り込める行が 0 件なのに「0件を登録しました」と成功の帯が出ること
  （テナント・賃貸マンション。Bug #66 の 6 と同じ形。鍵によって「2 回目」は断られるようになる）
- 2 つのタブで別々にプレビューした同じファイルを、ほぼ同時に確定すること（鍵が別なので両方通る。
  シート取込は、順に届けば指紋で止まるが、ほぼ同時だと両方が書き、履歴も 2 回できうる）
- 画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと（顧客の取込と同じ）
- 送信を中止（Esc など）すると、押せないボタンと状態の文字が残ること（顧客の取込と同じ。取込の画面を開き直せば抜けられる）
- 12 時間より古い確認画面（鍵の記録が消えたあとは、それぞれの重複の確認に頼る）
- 顧客の取込の画面の書き方を、共通の部品にそろえること
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）

## 7. ついでに見つかったこと（直さない）

- 推測（未測定）: テナントで同じ区画に契約中が 2 本並ぶと、区画の収支の月額・累計が 2 倍になる（`app/Services/Tenant/RentalIncomeService.php:72-79`）
- 推測（未測定）: ほぼ同時の 2 回は、ZEAL 会員・周辺ビル（同名のビル）で二重になり、物件・区画などは一意制約で生の SQL エラーになる見込み
- 推測: ZEAL 会員の重複の判定は氏名＋入会日だけ（`ZealMemberImportController.php:210-215`）。同姓同名で同じ日に入会した別人は、1 回目でも飛ばされる
- 実測: 工程表は 2 回目で工程の id が作り直される。推測: 取り込んだ工程を画面で直してから古い確認画面で送り直すと、その修正が消える
  （鍵によって、使用済みの画面からは送れなくなる）
- 実測: ZEAL 会員の取込のテストでは、テスト用スキーマに `settings` 表が無いので、`Settings::taxRate()` が警告を出して既定の 10.0 に落ちる
- `tests/Feature/Approval/LoginGuideTest.php` の `test_the_token_is_claimed_atomically` の docblock が、本番のキャッシュを database と書いている
  （本番は file）。今回このファイルを触るので、同じ作業の別のコミットで直せる（計画のときに利用者に確かめる）

---

## 8. 本番への反映

1. worktree でコミット → main repo で `git merge-base --is-ancestor 13.x import-double-submit` を確かめてから `git merge --ff-only import-double-submit`
   （13.x が先へ進んでいたら、13.x をこのブランチへマージしてから。リベースはしない＝記録のコミット番号を保つ）
2. `./deploy.sh`（DB の変更・新しい PHP クラス・依存の変更は無い）
3. 本番の確認は読み取りだけ: コンパイル済みビューの `php -l`・6 つの取込の画面が開くこと。実際の取り込みは利用者

本番への反映と push は、利用者の明示の承認があるときだけ行う。
