# 顧客CSVインポートの二重送信を止める — 設計書

作成日: 2026-09-27
対象: `app/Http/Controllers/Admin/CustomerImportController.php`・`resources/views/admin/customers/import.blade.php`・`app/Support/OneTimeAction.php`
前例: 決裁の CSV 取込（`Approval\UserImportController`。確認画面ごとに `OneTimeAction` の鍵を発行し、確定で 1 回だけ使う）
　　　docs/RULES.md Bug #65（`pageshow` で画面の状態を戻す・`event.persisted` で絞らない）・#66（顧客CSVインポートの確定を通した件）
　　　`housing/contracts/_buyer-select.blade.php`（送信中の印 `submitting` と `:disabled`）

---

## 1. 背景と依頼

顧客CSVインポート（`/admin/customers/import`、経営層のみ）の確定は 2026-09-27 に本番へ出た（Bug #66）。
そのとき範囲外に残した「二重送信を 1 回だけにする仕組みと、2 回目に出る文言の見直し」を直す。
利用者がこれから本番で実際の顧客 CSV を取り込むので、その操作そのものの安全を先に固める。

対話で決まったこと（2026-09-27）:

| 論点 | 決定 |
|---|---|
| 範囲 | 顧客CSVインポートだけ。ほかの取込の二重送信は記録だけ（§6）|
| 進め方 | **案 A**: サーバの 1 回限りの鍵 ＋ 画面の二度押し止め（§3）|
| 設計の 4 節 | ① サーバ側の鍵 ② 画面の二度押し止め ③ 案内の文言 ④ テストと確かめ方、いずれも承認済み |

---

## 2. 調査で分かった事実（2026-09-27・`13.x` = `f61290be`）

### 2.1 今の確定の流れ

`CustomerImportController::execute()` は、プレビューと確定を 1 つのメソッドで受ける（hidden の `confirmed` で分ける）。
確定は次の順に進む。

1. 部署の入力チェック（`department`。44〜54 行）
2. `csv_data`（base64）から CSV を読み直す（`loadCsv()`）
3. 全行を検査し直し、既存の顧客と照らし合わせる（姓・名・都道府県・市区町村が一致すれば重複候補）
4. 取り込める行が 0 件なら断る（160〜170 行）
5. トランザクションの中で書き込む（172〜247 行）

**1 回だけ送らせる仕組みは、画面にもサーバにも無い。**

### 2.2 2 回届いたときに起きること

- 前回の探り（2026-09-26・実測）: チェックを入れずに確定を 2 回送ると、2 回目は 4 の歯止めに着き
  「取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。」が出る。
  **案内に従うと、全員がもう一度入る。**
- コードからの読み取り（未実測。§5.1 のテストを先に書いて赤で確かめる）: **チェックを入れた確定を 2 回送ると、
  2 回目は案内すら出ずに全員がもう一度入る可能性が高い。** `$skipDupes = !$request->boolean('include_duplicates')` なので、
  2 回目は 1 回目で入った人を重複候補と数えたうえで、チェック済みのためそのまま取り込む。
  ⚠ **重複の確認に頼る直し方（処理を直列にするだけ・0 件で断るだけ）では止まらない。**
- ほぼ同時に 2 回届くと、どちらも 3 の照らし合わせを済ませてから書き込むので、両方が通りうる（未実測）。

### 2.3 手本: `OneTimeAction`

- `issue()` が 40 文字の鍵を作り、`claimFrom($request)` が hidden の `guide_token` を読んで使う（初めてなら true・2 回目以降は false）
- 使ったことはキャッシュに覚えておく（`Cache::add`）。**本番のキャッシュは file ドライバで、`FileStore::add()` が
  ファイルの排他ロックを取ってから書くので、ほぼ同時の 2 回でも片方しか通らない**（2026-09-25 に本番で確認済み。`OneTimeAction.php` の docblock）
- 覚えておく時間は `config('approval.guide_token_ttl_hours')`（既定 12 時間。`config/approval.php:33`）。
  それを過ぎた鍵は、また 1 回だけ通る
- `issue()` は発行を記録しない。**毎回あたらしい 40 文字を送れば何度でも通る**（守るのは「同じ鍵の 2 回目」だけ）
- 決裁の取込は、確認画面で `issue()`（`UserImportController.php:96`）、確定で行を検査し直したあと、書き込みの直前に `claimFrom()`（同 141 行）。
  決裁の 2 回目は、行の検査を通って鍵に着き、そこで断られる（既存の人は「更新」として数えられ、行数の 0 件の歯止めは
  到達しないとコメントに書かれている。同 128〜137 行）
- テストのキャッシュは `array`（`phpunit.xml:37`）で、1 つのテストの中では覚えている

### 2.4 既存の走査テストとの関係

- `LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim()` は、`claimFrom(` の呼び出しが **5 か所以上**あること、
  生の `claim()` を呼んでいないこと、`OneTimeAction` をエイリアスしていないことを見る → 6 か所目を足しても通る
- `test_every_guide_rendering_entry_point_claims_the_token_first()` が拾う入口は、`LoginGuide` / `PasswordReissuer` / `->toGuide(` を
  含むメソッドだけ → 顧客の取込は対象にならない
- `approval-phase2a`（別の会話が作業中）の差分は、`OneTimeAction.php`・`LoginGuideTest.php`・`config/approval.php`・
  顧客の取込のファイル・`docs/RULES.md`・`docs/BACKLOG.md`・`CLAUDE.md` のどれにも触れていない（2026-09-27 に `git diff 13.x...approval-phase2a` で確認）

### 2.5 画面の手本

- 送信中の印: `housing/contracts/_buyer-select.blade.php`（`submitting`・`:disabled="submitting"`・文言の切り替え）。Ajax のモーダルなので、見た目は `:style` で切り替えている
- `pageshow`: `approvals/admin/users/index.blade.php:358`（Bug #65。ブラウザの「戻る」で戻った画面の状態を戻す。`event.persisted` で絞らない）
- 確定のフォームは確認画面（POST の応答）に載っている。ボタンを包む要素は `x-show="importCount() > 0"` で、最初の状態はサーバが描く

### 2.6 空欄の住所と重複の確認（範囲外の件の裏づけ・未実測）

`BuyerCsvRow::from()` は空のセルを保存する値に入れない（`continue`）→ `Buyer::create()` で NULL になる。
一方、重複の確認は `$row->buyer['prefecture'] ?? ''` と**空文字**で比べる → **都道府県か市区町村が空欄の人は、
アップロードし直しても重複候補にならない可能性が高い**。§4.4 の案内が「取り込み済みの人は重複候補として出ます」と言い切らない理由。

---

## 3. 方針（案の比較）

| 案 | 止められる | 残る弱み | 採否 |
|---|---|---|---|
| **A サーバの 1 回限りの鍵 ＋ 画面の二度押し止め** | ダブルクリック・「戻る」からの送り直し・古い確認画面・ほぼ同時の 2 回 | 12 時間より古い確認画面の送り直しは鍵で止まらない（§6）| **採用** |
| B 画面の二度押し止めだけ | ダブルクリック | 「戻る」からの送り直し・別のタブの古い画面・JS が動かないとき | 不採用 |
| C サーバの鍵だけ | 二重の取り込みはすべて | ダブルクリックすると、画面に出るのは 2 回目の「送信済み」で、1 回目の完了の表示が見えない | 不採用 |
| D DB に取込の記録（一意制約）| すべて。書き込みに失敗したら鍵も自動で戻る | 本番の DB 変更（raw SQL）が要る。今の目的には過剰 | 不採用 |

---

## 4. 設計

### 4.1 ファイル構成

| ファイル | 変更 |
|---|---|
| `app/Support/OneTimeAction.php` | `claimFrom()` に hidden の名前の引数を足す（§4.2）|
| `app/Http/Controllers/Admin/CustomerImportController.php` | プレビューで鍵を発行・確定でほかの検査より先に使う・0 件の案内を直す（§4.3・§4.4）|
| `resources/views/admin/customers/import.blade.php` | hidden の `import_token`・二度押し止め（§4.5）|
| `tests/Feature/Admin/CustomerImportTest.php` | テストを足す・直す（§5.1）|
| `docs/RULES.md`・`docs/BACKLOG.md`・実装計画 | 記録（§4.6）|

**本番の DB 変更・新しい PHP クラス・依存の変更は無い**（`composer dump-autoload` は不要）。

### 4.2 鍵の部品（`OneTimeAction::claimFrom()`）

```php
public static function claimFrom(Request $request, string $field = 'guide_token'): bool
{
    $token = $request->input($field);

    return is_string($token) && self::claim($token);
}
```

- 省略したときは今の `guide_token` のまま → **決裁の 5 か所の呼び出しは変えない**
- 顧客の取込は `import_token` を使う。顧客の画面に `guide_token`（ログイン案内の鍵）という名前を載せると、あとで読む人を誤らせるため
- 覚えておく時間は決裁の設定（既定 12 時間）を共用する。二度押し（数秒）と「戻る」（ブラウザがページを覚えている間）には足りる
- docblock の「ログイン案内を出す POST に鍵を入れておき」「実測 5 箇所とも `name="guide_token"`」を、顧客の取込を含む形に直す

### 4.3 コントローラの流れ（`execute`）

```
部署の入力チェック（今のまま）
$confirmed を決める（今のまま）
確定なら、ここで鍵を使う:
    if ($confirmed && ! OneTimeAction::claimFrom($request, 'import_token')) → 取込の画面へ戻し、鍵の案内（§4.4）
loadCsv() 以降（今のまま）
プレビュー: 描画のデータに 'importToken' => OneTimeAction::issue() を足す
0 件の歯止め: 重複候補があるときの案内を §4.4 の新しい文言にする
書き込み（今のまま）
```

- **鍵はほかの検査より先に使う。** 書き込みの直前（決裁と同じ位置）に置くと、1 回目のあとに届いた 2 回目は、
  鍵より先に 0 件の歯止めに着き、今と同じ誤った案内が出る（§2.2）。顧客の取込では 2 回目の行が「重複候補」になり
  0 件の歯止めに着く点が決裁と違う
- 結果として「**確認画面 1 つにつき、送信は 1 回**」。断られたあとは同じ確認画面から送り直せないので、CSV をアップロードし直してもらう
  （断られると取込の画面に戻るので、画面の上ではふつうの操作と同じ）
- デプロイの前に開いた確認画面には鍵が無いので、デプロイ後にそこから送ると断られる（アップロードし直せば通る）
- 戻り先は取込の画面に固定する（今の決まり。Bug #64）

### 4.4 案内の文言

どちらも今と同じく、取込の画面の上に赤い帯（レイアウトが描く `session('error')`）で出る。

| 場面 | 変更後 |
|---|---|
| 鍵が使えない（同じ確認画面から 2 回目・鍵が無い・空・配列）| 「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。」|
| 確定の時点で取り込める行が 0 件・重複候補はある | 「取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。」（今の「チェックを入れてください」をやめる）|
| 確定の時点で取り込める行も重複候補も 0 件 | 変えない（「インポート可能なデータがありません。CSVを修正してください。」）|

- 2 つ目でチェックを勧めない理由: この場面でチェックを入れると、先に登録された人がもう一度入るため
- 1 つ目で「取り込み済みの人は重複候補として出ます」と言い切らない理由: §2.6
- 「顧客管理」は、住宅事業・不動産事業のサイドバーにある一覧の名前（`layouts/partials/sidebar.blade.php`）

### 4.5 画面（確定の欄）

```blade
<form method="POST" action="{{ route('admin.customers.import.execute') }}"
      x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
    @csrf
    （department・confirmed・csv_data の hidden は今のまま）
    <input type="hidden" name="import_token" value="{{ $importToken }}">
    （重複候補のチェックは今のまま）
    <div x-show="importCount() > 0" style="…今のまま…">
        <button type="submit" :disabled="submitting"
                class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                style="…今の style から cursor: pointer を抜いたもの…">
            インポート実行（<span x-text="importCount()">{{ $validCount }}</span>件）
        </button>
        <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="margin-left: 12px; font-size: 13px; color: #374151;"></span>
    </div>
    …
```

`csvImport()` に足すもの:

```js
submitting: false,
// 確定の二度押し止め: 1 回目は通して印を立て、2 回目以降は送信を取り消す（サーバの鍵が最後の歯止め）
onSubmit: function(event) {
    if (this.submitting) {
        event.preventDefault();
        return;
    }
    this.submitting = true;
},
// 「戻る」で確認画面がそのまま戻ったとき（bfcache）に印を下ろす（Bug #65 と同じく persisted で絞らない）
resetSubmit: function() {
    this.submitting = false;
},
```

- ボタンの文言「インポート実行（N件）」は変えない（件数の表示とテストの前提を保つ）
- 見た目は Tailwind の `disabled:` で切り替える。`:style` にすると Alpine の起動前にボタンが素の見た目で描かれるうえ、
  Top trap #5（同じ要素に `style` と `:style`）に触れるため。`cursor: pointer` は style に残すと `disabled:cursor-not-allowed` に勝つのでクラスへ移す
- 押せない間の理由はボタン横の文字で出し、`title` は使わない → Top trap #12 に当たらない。
  今の Blade コメントの「押せないボタンは disabled にせず隠す」は「件数が 0 のとき」の話なので残し、送信中だけ `disabled` にする旨を書き足す
- `role="status"` の要素は、ボタンを包む要素の中に**いつも置いて**文字だけ変える（`display: none` から出すと読み上げが届かないことがある）。
  空の `span` は幅を取らないので、送信前の見た目は変わらない。包む要素が隠れている間（V＝0 でチェック前）はボタンも出ないので送信できない
- 「戻る」で戻った確認画面で押すと、§4.3 の鍵でサーバが断り、鍵の案内が出る（ボタンが押せないまま固まるより、理由が分かる）
- JS が動かないとき・Alpine の起動前に押されたときは画面の歯止めが効かないが、サーバの鍵が止める
- ボタンを包む要素の出し分けとサーバが描く最初の状態（`x-show="importCount() > 0"`・V＝0 なら `display: none`）は今のまま

### 4.6 文書に記録するもの

- `docs/RULES.md`: Bug #67（番号は記録する直前に最新の `13.x` で数え直す）— 症状・原因（§2.2）・直し方・測って分かったこと
- `docs/BACKLOG.md`: 新しい節 ＋ 「顧客CSVインポートの確定を通す」の範囲外の「二重送信」の行に、対応した日と節を書き足す
- `OneTimeAction.php` の docblock（§4.2）・コントローラの docblock とコメント（「1 回だけ送らせる仕組みは範囲外」の記述を直す）
- 実装計画（`docs/superpowers/plans/2026-09-27-customer-import-double-submit.md`）に実測の記録

---

## 5. 検証

### 5.1 Feature テスト（`tests/Feature/Admin/CustomerImportTest.php`）

確定は今と同じく、プレビューが描いたフォームを分解してそのまま送り返す（Bug #47・#54 ②）。

| # | 確かめること | 形 |
|---|---|---|
| T1 | 同じ確認画面から 2 回送ると、2 回目は鍵の案内に着き、取り込みは 1 回だけ。**0 件の案内には着かない**（＝鍵を先に使っている）| 往復 |
| T2 | チェックを入れた確定を 2 回送っても取り込みは 1 回だけ（§2.2 の「案内すら出ない」場面。**先に書いて赤を確かめる**）| 往復 |
| T3 | プレビューごとに鍵が変わり（空でない）、別のプレビューからは取り込める | 往復 |
| T4 | 鍵が無い・空・配列の送信は断られ、500 にならず、何も入らない | 往復（データセット 3 つ）|
| T5 | プレビューのあとに同じ人が登録されると、新しい 0 件の案内に着き、チェックを勧めない | 往復 |
| T6 | 確定のフォームに `x-on:submit`・`x-on:pageshow.window`、ボタンに `:disabled` と `disabled:` のクラス（style に `cursor` が無い）、ボタンを包む要素の中に `role="status"` がある。どれも**その要素に**付いていること（Bug #47）| 構造 |
| T7 | `onSubmit()` は 1 回目を通して 2 回目を取り消し、`resetSubmit()` のあとはまた通す | 画面の `<script>` を node の vm で動かす（件数のテストと同じ流儀）|

- 既存の `test_confirming_only_duplicates_without_the_check_imports_nothing` は期待する文言を §4.4 の新しい文言に直す
- 確定のフォームを分解する部品（`confirmForm()`）で、`import_token` の hidden が描かれていることも見る
- node の vm を動かす部品は、今の `importCountsInNode()` を一般化して共用する

### 5.2 変異テスト

Bug #44 の手順（先にコミット・毎回 `git status --porcelain` が空・着弾を確かめる・落ちた理由の文言まで照らし合わせる）。
候補: 鍵を使う処理を消す ／ 0 件の歯止めの後ろへ動かす ／ 書き込みの後ろへ動かす ／ 鍵を固定値にする ／
hidden の名前を画面とコントローラで食い違わせる ／ `claimFrom()` が名前の引数を無視する ／ 案内を元に戻す ／
`onSubmit()` が取り消さない・印を立てない ／ `x-on:submit`・`:disabled`・`role="status"`・`pageshow` をそれぞれ外す・別の要素へ移す ／
`resetSubmit()` が印を下ろさない ／ 決裁の既定の名前を変える、など約 20 通り＋カナリア。全表は実装計画に書く。

### 5.3 全件テストとビュー

- 全件テスト（worktree。`APP_KEY` を環境変数で渡す。今は 2346 本）
- コンパイル済みビューの `php -l`（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

### 5.4 ローカルの実ブラウザ（使い捨て SQLite ＋ `artisan serve` ＋ Playwright）

- `disabled:` のクラスのため、worktree で CSS をビルドしてから見る（main repo の `node_modules` で `vite build`）
- ダブルクリックしても確定の POST は 1 回だけで、DB は 1 回分・完了の案内が出る
- 確定の応答を遅らせて、「取り込んでいます…」とボタンの無効化（見た目・`disabled`）を見る
- 完了のあと「戻る」で確認画面へ戻り、押すと鍵の案内が出る（bfcache で戻らない場合はそのことを記録する）
- 375px と 1440px で横スクロールが無い・コンソールのエラーが 0

---

## 6. やらないこと（BACKLOG に書く）

- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）
- 都道府県か市区町村が空欄の行が重複候補にならない件（§2.6。前回からの範囲外）
- 書き込みに失敗したあと、同じ確認画面から送り直すこと（アップロードし直す）
- 12 時間より古い確認画面からの送り直し（鍵の記録が消えたあとは重複の確認に頼る）
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 鍵の画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと

---

## 7. 本番への反映

1. worktree でコミット → main repo で `git merge --ff-only`
2. `./deploy.sh`（`npm run build` で `disabled:` のクラスを含む CSS をビルドし直す）。DB 変更・新しい PHP クラスは無い
3. 本番の確認は読み取りだけ: コンパイル済みビューの `php -l`・取込の画面が開くこと。実際の取り込みは利用者
