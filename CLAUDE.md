# ミツワ都市開発 経営管理システム (manage)

Laravel 12 / PHP 8.5.4 (local) + 8.3 (prod) / MySQL 8 / Blade + Alpine.js 3 + Tailwind v4 (Vite build) / SheetJS (CDN)
**Production**: https://www.mitsuwat.co.jp/system/manage — 約 200 ルート
**Repo**: main branch is `13.x`（"main" ではない）

## Quick context

- 作業は git worktree（`.claude/worktrees/<name>`）で行う
- 詳細仕様 / 過去バグ / モジュール一覧は @docs/* を参照すること

## 🚨 Top traps — 過去にやらかしたもの

| # | 罠 | 正解 |
|---|---|---|
| 1 | Blade で `env(...)` 直接呼び（`config:cache` 後に空文字、本番 Google Maps が無音で死ぬ）| `config/services.php` 経由 → Blade では `config('services.xxx.yyy')`。Bug #17 |
| 2 | Blade Anonymous Component 属性内 `&quot;`（本番 view:cache で 500 syntax error）| PHP 連結で組む `:cancel-url="route($dept.'.customers.index')"`。Bug #21 |
| 3 | `<option>` を `<template x-for>` で生成（x-model 同期前にレンダリングされ値ズレ）| 必ず `@foreach($items as $i)` で静的注入。Bug #16 |
| 4 | `x-data="() => ({})"` のアロー関数（`>` が HTML 終了タグとして解釈）| `x-data="myFunc()"` + 別 `<script>` で `function myFunc() { ... }` 定義 |
| 5 | 同一要素で `style=` + `:style=` 併用（Alpine が静的 style を上書き）| 全部 `:style="..."` に merge |
| 6 | `@keydown.enter="save()"` を日本語入力フィールドに置く（IME 変換確定 Enter で誤発火→未確定のまま保存）| `@keydown.enter="$event.isComposing \|\| save()"` |
| 7 | 「効かないクラス一覧」を信じて、コンパイル済みのクラスをわざわざ inline style に書き換える | **Tailwind クラスは普通に書いてよい**（`./deploy.sh` が `npm run build` するので本番は必ず最新。2026-07-15 に組み込み）。**ローカルで見た目を確認する時だけ手で `npm run build`**。旧一覧は 12/12 が誤りだった（`docs/*.md` も走査対象で、一覧に書いた事自体がそのクラスを実在させていた → 2026-07-15 に `@source not "../../docs"` で除外し解消）。測るなら main repo で `grep -oE "\.my-class[,{:>~+ ]" public/build/assets/app-*.css`（`:` `.` `[` を含むなら `grep -oF '.gap-1\.5'`）。⚠ 走査対象は `resources/` だけでない——`app/Enums/UnitStatus.php` は Tailwind クラス文字列を返すので `app/` も必要。詳細は @docs/RULES.md「Vite Build」+「Tailwind 監査の落とし穴」。Bug #19 |
| 8 | Object.assign 引数順序を逆転（factory がリテラルの getter を評価して static 値に焼き付け、Alpine reactivity 死亡）| 必ず `return Object.assign({...existing with getters...}, factoryResult);` の順。getter は target 側に置く |
| 9 | JSON API を **GET** で叩く `fetch` に `X-Requested-With` を付け忘れる（セッションの直前 URL がその API で上書きされ、バリデーションエラー時の `back()` が生の JSON ページへ飛んで**入力が全消失**）| `headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }` を必ず付ける（`Accept` だけでは効かない）。⚠ **GET だけが対象**（`storeCurrentUrl()` は GET しか記録しない）。⚠ **「何も入力せず送信」では再現しない** — Ajax を一度叩いてからエラーを出すこと。走査テスト `AjaxFetchSessionGuardTest` が自動で拾う（2026-08-03 に `/api/realestate/` 限定から**自社 API 全体**へ拡張）。Bug #35 |
| 10 | `__()` を通る機能を足したのに `lang/ja/<group>.php` を作らない（`APP_LOCALE=ja` かつ fallback も ja なので **en にも落ちず生の翻訳キーが画面に出る**）| 対応する `lang/ja/*.php` を必ず追加する。⚠ **`$request->validate()` のようにフレームワーク内部から `__()` が呼ばれる経路は `grep -rn "__('"` に出ない** — 実際にエラーを出して画面で見る。⚠ **`phpunit.xml` の `APP_LOCALE=ja` / `APP_FALLBACK_LOCALE=ja` を消さない**（消すとテストが locale=en で走り、この種の欠陥を原理的に検出できなくなる）。Bug #36 |
| 11 | フォームに項目を足したのに `lang/ja/validation.php` の `attributes` に和名を書かない（エラー文に **`guarantor1 name` のような英字**が出る）| `attributes` に和名を追加する。画面ごとに語が変わるキー（`name` `address` 等）は**そのコントローラの `validate()` **第3引数**で上書き（`validate($rules, $messages, $attributes)` — **第2引数は messages**）。走査テスト `JapaneseValidationMessagesTest` が和名漏れを自動で拾う。Bug #37 |
| 12 | `disabled` なボタン自身に `title` を付けて「押せない理由」を出そうとする（**どのブラウザでも表示されない**。`disabled` な要素はホバーイベントを発火しない。HTML には `title` が出るのでテストも `view:cache` も全部通り、無音で死ぬ）| ホバーを受けられる **`<span title="…">` でボタンを包む** ＋ 画面に理由の領域があれば `aria-describedby` で紐づける（`disabled` はフォーカス不能なので tooltip だけでは届かない）。Alpine なら `:title="cond ? reason : null"`（`''` だと空の `title=""` が残る）。⚠ **検証は「HTML に出るか」では不可能** — 実ブラウザでホバーするか `document.elementFromPoint()` から祖先を辿る。Bug #43 |
| 13 | 走査テスト（ラチェット）を「直したファイルを配列に並べる」形で書く（**未修正のファイルは検査対象に入らないので永遠に緑**。実測で 19 本が野放しだった）| **対象を全件分類する**形にする — `fetch` を持つ Blade を機械的に列挙し、どのリストにも無ければ落とす（`AjaxErrorFeedbackTest::test_every_fetch_view_is_classified`）。⚠ 検査文字列に**引数名を決め打ちしない**（`(r)` 決め打ちが `(res)` を見逃した）。⚠ **単一の「正準パターン」を機械適用しない** — null 返し / エンベロープ / throw の 3 方式が併存し、どれも正当。Bug #45 |
| 14 | **view データに `'errors'` キーを渡す**（Blade の `$errors` = `ViewErrorBag` を上書きする。そのビューが `$errors->any()` を呼んだ瞬間に **`Call to a member function any() on array` で 500**）| 行エラーなど独自のエラー配列は **`rowErrors` のような別名**にする。⚠ **画面を開くだけでは分からない** — 壊れるのはそのデータを渡す経路（取込ならファイルを上げた後のプレビュー）だけ。⚠ **200 を見るだけのテストでは守れない** — ビュー側だけ `$errors` に戻すと**エラー表示が画面から消えるのに例外は出ず全テストが緑**（実測）。コントローラ側の件数と画面の表示を突き合わせること。走査テスト `ImportPreviewRenderTest` が「view に `'errors'` を渡していない」を全件分類で自動で拾う。Bug #53 |
| 15 | 日付の検証を `strtotime()` でやる（**存在しない日付を繰り上げて通す**。`2026-02-30` は 3/2 と解釈されるのに入力文字列がそのまま返り、本番 MySQL の strict mode が `Incorrect date value` で落ちて `rollBack()` ＝ **1 行の打ち間違いで数百行の取込が丸ごと消える**。逆に `strtotime('1970-01-01')` は `0` ＝ falsy で epoch だけ理由なく拒否される）| **`checkdate()` で存在を判定する**（`App\Support\CsvDate::normalize()` に集約済み）。`preg_match` は**書式**しか見ていないので存在判定の代わりにならない。⚠ **テストの SQLite は `'2026-02-30'` をそのまま格納する**ので DB には守ってもらえない。⚠ 併せて「テストが緑でも測っていない」7 通りの実測（Laravel を起動しない Unit テストは `config/app.php` でなく `php.ini` の timezone に支配される / 手組みリクエストの往復テストは手書き部分を守らない / `assertSee` は警告とエラーを区別しない 等）も同項に。Bug #54 |
| 16 | 取込の**列名自動判定**を「語の部分一致」だけで書く（実運用の見出しは `階` `空` の**1 文字**で当たらず、総階数が空・空き 0 件で入って**空室率が全棟 0%**。プレビューは警告 0 行・結果も「新規 187 件」で**完全成功に見える**）| 1 文字の見出しを `^階$` `^空$` で明示する（判定は空白除去後に当てるので `^…$` は 1 文字にだけ効く）。⚠ **取込前に列マッピングの画面を必ず読む** — 件数列が「使わない」だと台帳が 0 で埋まる。⚠ **同一年月スキップがあるので取り直しても直らない**（調査回を手で消すか編集するしかない）。⚠ **同名のビルは無音で 1 棟に畳まれる**（理由は結果メッセージに出ない）→ 流す前に重複名を数える。Bug #55 |
| 17 | 起動後は必ず表示される要素（PC 展開サイドバー）に `x-cloak` を付ける ／ ページの `<script>` で幅を測るのに、Alpine による表示の切り替えの**前後どちらか一方でしか**測らない（パース中の script は Alpine（defer）より前に走る。`x-cloak` で起動前だけサイドバーが消えて表示領域が **220px 広く**、1024px 以上のある幅でだけ「スクロールできるのにヒントが出ない」「初期スクロールが 220px 手前で止まる」。テストも `view:cache` も HTML も全部正しい）| **展開サイドバーに `x-cloak` を戻さない**（2026-09-13 に外した。折りたたみ版・ドロワー・グループの中身は残す）。横スクロールのヒントは**パース中に判定し DOMContentLoaded でも測り直す**（DCL だけだと JS が遅いとき DCL まで既定の「表示」が描かれる。300ms 遅延で実測）。サイドバーの開閉は `body` の `x-init` が `$nextTick` → `requestAnimationFrame` → `resize` を送る（⚠ `$nextTick` だけだと Alpine の x-show がまだ切り替えておらず開閉前の幅で測る）。⚠ `requestAnimationFrame` を**初回**の計測に使うのは不可（非表示のタブで止まる）。⚠ **Alpine のタイミングは画面が見えているブラウザで測る**（非表示のタブでは x-show が setTimeout 経由になり、壊れた形でも正しく動いて見えた）。⚠ **1024px 未満では再現しない**。走査テスト `LayoutMeasuringScriptTest`（全件分類・方針つき）と `LayoutSidebarCloakTest` が構造を守る（Chart.js・Alpine の `init()` の中の計測は見えない）。Bug #56 |
| 18 | 論理削除するモデル（区画・買主など）を**子から読むリレーションに `withTrashed()` を付けない** ／ 編集画面の選択肢を削除済みを除いて組む（区画を 1 つ消した瞬間に、投資の画面が 500・修繕が「共用部」と誤表示し保存で共用部に書き換わる・問合せの希望区画が黙って消え保存で中間テーブルからも消える。`exists:units,id` も論理削除を見ない）| 子から読むリレーション（belongsTo / belongsToMany）は `withTrashed()`、並べるリレーション（`Property::units` などの hasMany）には付けない。表示は `Unit::display_label`（削除済みなら「（削除済み）」・区画ページへのリンクを張らない。印は DB に保存しない）。編集画面の選択肢は `Unit::includingTrashed($今の区画)`（⚠ 列を絞る `get([...])` に `deleted_at` を入れる。忘れると印が黙って消える）。入力チェックは登録 `Rule::exists('units', 'id')->withoutTrashed()`・更新は今の区画だけ削除済みでも通す。所属チェックも `Unit::withTrashed()`。走査テスト `DeletedUnitReferenceTest::test_every_relation_to_units_is_classified` が全件分類で守る。⚠ **物件（`Property`）は別の方式** — 関連データ（区画・契約・投資・修繕・問合せ）が残る物件は削除させず（`Property::deletionBlockers()`）、保存時も削除済みの物件を拒否する（`Rule::exists('properties', 'id')->withoutTrashed()`）＝削除済みの物件は子を持たないので、子→物件のリレーションには `withTrashed()` を**付けない**（付けると `whereHas('property')` が削除済みの物件まで拾い一覧・集計の意味が変わる）。⚠ **区画の一意制約（物件＋表示名）は削除済みの行も含む** — 登録・取込の重複チェックは `Unit::withTrashed()` で引き、削除済みの同名は復元する（画面の `UnitController::store`・区画の CSV 取込。見ないと確定で一意制約に当たり取込の全行が巻き戻る）。Bug #58 / #59 / #60 |

全 60 件の詳細バグカタログ + 各種パターン: @docs/RULES.md

## 🔌 利用可能なプラグイン

| プラグイン | いつ使う | 注意点・このプロジェクト固有のルール |
|---|---|---|
| **laravel-boost** | 常時（Laravel 規約 + Artisan）| **本プロジェクト最優先**。Eloquent クエリ / migration / コマンド系は boost の規約に従う |
| **commit-commands** | コミット / PR 作成時 | `/commit`（通常）、`/commit-push-pr`（ユーザー明示指示時のみ）、`/clean_gone`（マージ済 branch 掃除）。手動 `git commit` は HEREDOC 必要時のみ |
| **superpowers** | 新機能設計 / 並列タスク / 多段実装 | 機能追加前に `/brainstorming`、独立 2+ タスクは `/dispatching-parallel-agents`、複雑実装は `/writing-plans` + `/executing-plans` |
| **claude-mem** | 過去判断調査 / 計画 / PR 監視 | `/mem-search`（過去セッション横断検索）、`/make-plan` → `/do`（計画→実行）、`/babysit`（PR 監視） |
| **context7** | ライブラリ最新 API 確認 | Laravel 12 / Alpine 3 / Tailwind v4 を推測ではなく公式 doc で確認したい時 |
| **playwright** | デプロイ後の動作確認 / E2E | 本番 URL に対する Playwright 検証 |
| **code-review** | PR 提出前のセルフレビュー | `/review` で過去バグ + project conventions チェック |
| **feature-dev** | 大型機能の architect / explore / review | 軽微修正には重い。3+ ファイルにまたがる新機能や横断調査で使う |
| **frontend-design** | UI を新規作成する時 | **Tailwind は普通に使ってよい**（2026-07-15 に凍結解消＋`deploy.sh` へビルド組み込み済み。任意値 `min-w-[140px]` も可）。ローカルで確認する時だけ手で `npm run build`。⚠ かつての「効かないクラス一覧」は誤りだったので信じない。手順は @docs/RULES.md「Vite Build」、Bug #19 |

## ⚙️ Workflow

### Local dev cache クリア
```bash
php artisan view:clear && php artisan route:clear && php artisan config:clear
# 必要なら storage/framework/views を直接消す:
sudo rm -f storage/framework/views/*.php && brew services restart httpd
```

### コミット → 本番反映 の正しい順番

1. worktree 内で commit-commands プラグインを使用: `/commit`
2. **main repo (`/Users/masanori/site/manage`) で** `git checkout 13.x && git merge --ff-only <worktree-branch>`
3. **新規 PHP クラスを追加した場合のみ**: main repo の cwd で `composer dump-autoload`
   - ⚠ worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれ、main repo の Apache が worktree を参照する事故になる。必ず main repo の cwd で実行
4. `./deploy.sh`（`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache`）
5. push to origin/13.x はユーザー明示指示があった時のみ
6. （オプション）Playwright で本番動作確認

### 新機能の検討フロー
1. `/brainstorming`（superpowers）で要件・設計を固める
2. `/writing-plans` または `/make-plan`（claude-mem）で詳細実装プラン作成
3. **Plan Mode** で plan ファイルに書く → `ExitPlanMode` でユーザー承認
4. `/do`（claude-mem）または直接実装
5. `/review`（code-review）でセルフレビュー
6. `/commit` → main へ FF-merge → `./deploy.sh`

### deploy.sh の動作
- **`npm run build` を実行してから** rsync する（2026-07-15 に組み込み）。ビルド失敗時は本番へ何も転送せず中断
- rsync で本番（さくらレンタル `mitsuwa-ud@www3586.sakura.ne.jp`）にアプリ + vendor + public を転送
- ssh で `umask 077` のうえ `php artisan config:cache && route:cache && view:cache` を実行（本番の `.env` と `bootstrap/cache/config.php` は秘密入りなので 600 を保つ。PHP は本人の権限で動くので 600 で読める。2026-09-14）
- `composer install` は走らない → 新規依存は **ローカルで `composer install` → vendor 同期で本番反映**
- `CLAUDE.md` `docs/` `.claude/` `tests/` 等は rsync 除外（開発用ファイルは本番に送らない）
- 旧バンドルの掃除: `public/build/` だけ `--delete` 付きで再同期（2026-07-15 に追加）。転送先が 2 つあるのは APP_PATH = Laravel が manifest を読む側 / WEB_PATH = ブラウザが実ファイルを取る側の両方に配るため
- ⚠ **`public/` 全体に `--delete` を付けるのは厳禁**（`public/storage` は `storage/app/public` への symlink ＝ 本番のアップロード物を消しうる）。`--delete` してよいのは Vite 出力しか入らない `public/build/` のみ
- ⚠ **`storage/` は rsync 除外**（2026-09-16 に追加）。本番の添付を手元の中身で上書きすると、「同じパス・同じ大きさなら送り直さない」判定のバックアップが変更を拾わず、控えと本番が静かに食い違う。除外は `--exclude='/storage/'` と**先頭スラッシュ付き**で書く（付けないと `public/storage` の symlink まで巻き添えになる）。以前の `storage/app/backup-work` はこれに含まれるので置き換えた

### Server environment
- macOS Apple Silicon, zsh, Homebrew httpd（`brew services restart httpd`）
- BSD sed: `sed -i ''`（GNU 構文 NG）
- DB migration は raw SQL: `sudo mysql manage < file.sql`（Laravel migration ファイル管理ではない）

### 定期実行とバックアップ（本番）
- 定期実行: さくらの CRON（5 分おき・1 件）が `schedule:run` を起動（予定は `routes/console.php`、時刻は `config/app.php` の `schedule_timezone`=Asia/Tokyo）。キューは database で、`queue:work --stop-when-empty` を `schedule:run` の起動ごと（＝5 分おき）に回す（常駐禁止のため）。予定は「その分に一致したら実行」なので、決まった時刻の仕事は `0-4 3 * * *` のように 5 分の幅を持たせる（CRON の起動のずれで無言で飛ばないように）
- 夜間バックアップ: `ops:backup`（3:00〜3:04 の回・キュー処理より先・メンテナンス中も実行。画面の出力は `storage/logs/backup-command.log`）→ DB 全体と `storage/app/{public,private}` を AES-256-GCM で暗号化してさくらのオブジェクトストレージへ（添付は `files/<キー識別子>/` に差分だけ送る）。自分で扱った失敗は終了コード 3（`BackupCommand::HANDLED_FAILURE`）で自分で通知し、それ以外の 0 でない終了は `routes/console.php` の onFailure が、終了コードが得られない停止（kill などのシグナル）は同じファイルの ScheduledTaskFailed の listener が通知する（本番 FreeBSD の sh は予定のコマンドを sh を挟まずに直接実行するため、kill されると終了コードではなく ProcessSignaledException になり onFailure まで進まない。手元の macOS の sh＝bash では 137 になるので、テストは `exec` を付けて再現する）。本番に sodium は無い。記録の時刻は UTC。本番の `LOG_LEVEL` は `error`（2026-09-14 ユーザー判断）なので、完了（info）と注意（warning）は laravel.log に残らず `backup-command.log` にだけ出る。成否はこれと失敗メールで見る（`scheduler.log` の DONE は失敗でも出るので使わない）。手順は @docs/運用_バックアップとメール.md
- ⚠ `storage/app/{public,private}` のファイルは**上書き保存しない**（新しい内容は新しい名前で保存する）。バックアップは「同じパス・同じ大きさなら送り直さない」判定のため、上書きすると変更がバックアップに入らない
- ⚠ コマンドの画面に「このコマンドを打ってください」と案内するときは、`php artisan …` と書かずに `PHP_BINARY` で組み立てる（`app/Console/Commands/Concerns/SuggestsArtisanCommands.php`）。さくらで `php` とだけ打つと既定の PHP 7.4 が動き、英語のエラーで止まる

## 📋 Conventions

### Form
- 項目間: `margin-bottom: 26px`
- ベース: `customers/_form.blade.php`（`form-input`, `gap-3`, `grid grid-cols-1 sm:grid-cols-2`）
- 金額 input に `value="0"` 既定値を入れない（空欄スタートが原則）
- 同一 `name` 属性 + `x-show` は片方が hidden でも送信される → hidden + Alpine var or `:disabled` で除外
- 日本語入力フィールドの Enter ハンドラには `$event.isComposing ||` を必ず挟む
- 全角数字は global listener が `input[inputmode="numeric"]` `input[type="number"]` で半角自動変換（`layouts/app.blade.php` 注入、新規フォームへ自動適用）

### Display
- 金額: 税抜、末尾「円」、`28,500,000円` 形式（`¥` 接頭辞 NG）
- 粗利: `color: #047857; font-weight: 700`
- 建蔽率 / 容積率: 整数表示（小数なし）
- 坪数: `AreaConverter::sqmToTsubo()` 経由（㎡ × 0.3025 の**切り捨て**2桁）。`÷3.30579` も float の `floor` も誤差が出る。Bug #33
- 坪単価: `TsuboPrice::perTsuboYen()` / `perTsuboManLabel()` 経由。丸めは常に**切り上げ**（分譲地は万円・小数第1位、テナントは円・整数）。**丸めは1回だけ**（円で丸めてから万円で切り上げると切り上げが破れる）。float の `ceil` も不可。Bug #34
- 消費税: **建物のみ課税**（土地は非課税）。`ConsumptionTax` 経由。⚠ **方向が 2 つある** — **税額は切り捨て**だが、**税込→税抜の逆算だけは切り上げ**（切り捨てると税込に戻したとき 1 円足りない: 12,500,000 → 11,363,636 → 12,499,999）。⚠ **逆算は画面では PHP を通らない**（税込入力欄は `name` 無しで送信されない）。実体は Alpine の `Math.ceil` が 3 箇所で、走査テスト `TaxExclusiveCeilingJsTest` が守っている。Bug #41 / #42
- ステータスバッジ: モデルの `badgeStyle()` メソッド経由（Tailwind クラス指定 NG）
- 担当者名: 苗字のみ表示（同姓重複時のみフルネーム）
- 期: 5/1 始まり（5月〜4月）。ZEAL/DAD は 6/1 始まり
- 採算表/試算表は基本「**万円単位**」（Excel取込の単位既定値も万円）

### Filter bar（一覧画面）
- 即時フィルタ: `onchange="document.getElementById('filter-form').submit()"`
- クリアボタン: `h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400`
- ページネーション: 20 件 / page

## Laravel-specific quirks

- Department 判定: `resolveDepartment()`（`request()->segment(1)` ベース）— `defaults()` は Laravel 12 で URL パラメータ無しだと効かない
- `User` モデルに `deleted_at` 列なし → `User::orderBy('name')` のみ、`whereNull('deleted_at')` 禁止
- `Buyer`・`Unit`（テナントの区画）は SoftDeletes → 参照する側のリレーションで常に `->withTrashed()` + edit 画面では現在の値を必ず含める（区画は `Unit::includingTrashed()`、表示は `display_label`。Bug #12 / #58）
- `Property`（テナントの物件）も SoftDeletes だが、関連データ（区画・契約・投資・修繕・問合せ）が残る物件は削除できない（`Property::deletionBlockers()`。Bug #59）＝削除済みの物件を指す子は作られない前提なので、子→物件のリレーションに `withTrashed()` を足さない。物件にリレーションを足したら `DELETION_BLOCKING_RELATIONS` / `DELETION_IGNORED_RELATIONS` のどちらかに分類する（`PropertyDeletionGuardTest` が両側から全件分類で守る）
- `re_projects` のカラムは `project_name`（`name` ではない）
- 既定都道府県: 愛媛県
- 外部 CDN: `cdn.jsdelivr.net` のみ許可（`cdnjs.cloudflare.com` は本番でブロック）

## 🏗 Completed modules（既存資産を再発明しないよう）

| 領域 | 主要 URL | Controller / Model 例 |
|---|---|---|
| テナント管理 | `/tenant/*` | `Tenant\*Controller`（約80ルート）|
| 不動産 仕入れ案件 | `/realestate/procurements` | `RealEstate\ProcurementController`（Excel取込内蔵）|
| 不動産 分譲地PJ | `/realestate/projects` | `RealEstate\ProjectController`（Excel取込内蔵）|
| 不動産 契約 | `/realestate/contracts` | `RealEstate\ReContractController` |
| 不動産 仕入れ先 | `/realestate/suppliers` | `RealEstate\SupplierController` |
| 住宅事業（建売/注文住宅）| `/housing/*` | `Housing\*Controller` |
| 住宅事業ダッシュボード | `/housing` | `Housing\HousingDashboardController` |
| 賃貸マンション | `/mansion/*` | `Mansion\*Controller`（`ms_*` テーブル）|
| DAD（土木）| `/dad/*` | `Dad\*Controller`（Excel取込内蔵）|
| ZEAL（フィットネス）| `/zeal/*` | `Zeal\*Controller` + 経営試算表 / 本部 Google Sheets 連携 |
| 経営ダッシュボード | `/dashboard/executive` | `DashboardController::executive`（5事業横断）|
| 買主マスタ（部署横断）| `/buyers` | `CustomerController`、SoftDeletes、CSV import |
| 添付ファイル | ポリモーフィック | `AttachmentController`（TYPE_MAP と routes/web.php の `where` 正規表現を同期。Bug #20）|

詳細構成: @docs/ARCHITECTURE.md / 実装履歴・優先度: @docs/BACKLOG.md

## 📚 Detailed docs

- @docs/ARCHITECTURE.md — ディレクトリ構成、モデル一覧、認可マトリクス
- @docs/RULES.md — Bug #1–60 + Tailwind 不可クラス/監査の落とし穴 + Excel/SheetJS + 全角→半角自動変換 + 郵便番号 API
- @docs/BACKLOG.md — 完了済み機能の優先度別一覧（優先度 1〜5 全て本番稼働中）
