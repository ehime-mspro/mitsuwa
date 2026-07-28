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

全 33 件の詳細バグカタログ + 各種パターン: @docs/RULES.md

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
- ssh で `php artisan config:cache && route:cache && view:cache` を実行
- `composer install` は走らない → 新規依存は **ローカルで `composer install` → vendor 同期で本番反映**
- `CLAUDE.md` `docs/` `.claude/` `tests/` 等は rsync 除外（開発用ファイルは本番に送らない）
- 旧バンドルの掃除: `public/build/` だけ `--delete` 付きで再同期（2026-07-15 に追加）。転送先が 2 つあるのは APP_PATH = Laravel が manifest を読む側 / WEB_PATH = ブラウザが実ファイルを取る側の両方に配るため
- ⚠ **`public/` 全体に `--delete` を付けるのは厳禁**（`public/storage` は `storage/app/public` への symlink ＝ 本番のアップロード物を消しうる）。`--delete` してよいのは Vite 出力しか入らない `public/build/` のみ

### Server environment
- macOS Apple Silicon, zsh, Homebrew httpd（`brew services restart httpd`）
- BSD sed: `sed -i ''`（GNU 構文 NG）
- DB migration は raw SQL: `sudo mysql manage < file.sql`（Laravel migration ファイル管理ではない）

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
- `Buyer` は SoftDeletes → リレーション側で常に `->withTrashed()` + edit 画面では現在の buyer を必ず含める
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
- @docs/RULES.md — Bug #1–33 + Tailwind 不可クラス/監査の落とし穴 + Excel/SheetJS + 全角→半角自動変換 + 郵便番号 API
- @docs/BACKLOG.md — 完了済み機能の優先度別一覧（優先度 1〜5 全て本番稼働中）
