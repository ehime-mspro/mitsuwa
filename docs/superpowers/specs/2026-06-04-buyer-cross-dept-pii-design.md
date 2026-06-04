# 買主マスタ 部署横断PII対策 — 設計

- 日付: 2026-06-04
- 対象: `CustomerController`（`checkDuplicate` / `addToDepartment`）、`routes/web.php`、`resources/views/buyers/_form.blade.php`
- 区分: セキュリティ（IDOR / PII露出）。前セッションのセキュリティ監査で「監査範囲外・要設計判断」として繰り越した残課題
- 方針決定: **案①（一致厳密化＋取込検証）**。機能（部署共有）は維持し、PII露出の最小化と認可強化で対応する

## 背景・問題

買主マスタ（`buyers`）は住宅事業（housing）と不動産事業（realestate）で共有され、`buyer_departments` ピボットで部署に紐づく。同一人物が両事業の顧客になるため、登録フォームで「他部署に同名の買主が居れば検知し、自部署にも追加（部署共有）する」フローが意図的に用意されている（**日常的に使う重要機能**）。

しかしこのフローに以下の脆弱性がある。

| ID | 箇所 | 問題 |
|----|------|------|
| V1 | `check-duplicate` ルート（`routes/web.php` 1429-1430）| middleware ゼロ。買主登録は `role:executive,manager` 限定なのに、重複検知は staff 含む全認証ユーザーが叩ける |
| V2 | `CustomerController::checkDuplicate`（297-338）| 姓名のみでヒットし、他部署買主の `full_name`・住所（都道府県＋市区町村）・`id` を無制限に返却。姓名を総当たりすれば他部署買主のPIIを列挙できる |
| V3 | `CustomerController::addToDepartment`（343-362）| Route model binding の `Buyer $buyer` に対象買主の正当性検証が無く、任意の buyer ID を自部署へ取込→`show` で全PII閲覧可能 |

`department.access`（引数なし）は JSON body の `department` を見て「ユーザーが**その部署に所属するか**」のみ検証するため、対象買主の元所属は検証されない（V3 を防げない）。

## 要件

- 部署共有フロー（重複検知→他部署買主の自部署取込）は**維持**する
- 他部署買主のPIIは、本人を既に把握している正規利用者にのみ必要最小限を提供し、総当たりによる列挙を防ぐ
- 認可は買主登録（`role:executive,manager` ＋ 自部署所属）と同一ラインに揃える

## 設計

### 1. 認可強化（V1）

`routes/web.php` の check-duplicate ルートに認可を追加する。

```php
Route::post('/api/customers/check-duplicate', [\App\Http\Controllers\CustomerController::class, 'checkDuplicate'])
    ->middleware('role:executive,manager')   // staff を排除
    ->middleware('department.access')          // JSON body の department で自部署所属を検証
    ->name('api.customers.check-duplicate');
```

- `add-department` は既に `role:executive,manager` ＋ `department.access` 済み（変更不要）
- これで「無認可・無関係部署」からのアクセスを排除し、登録と同じ認可ラインに揃う

### 2. 重複検知の厳密化＋戻り値最小化（V2）

`checkDuplicate` を**自部署検索**と**他部署検索**に分離する。

- **自部署（二重登録警告）**: 姓名一致で検索。`id`・`full_name`・住所を返す（自部署のPIIは元々閲覧可。現状の二重登録防止UXを維持）
- **他部署（共有候補）**: **姓名＋都道府県＋市区町村の完全一致を必須**とする。`prefecture`・`city` のいずれかが空なら他部署検索は実行しない。戻り値は取込用 `id` と存在情報のみで、**住所（`address`）は含めない**

戻り値の構造（イメージ）:

```php
// 自部署ヒット
['id' => .., 'full_name' => .., 'address' => .., 'same_dept' => true,  'other_dept' => []]
// 他部署ヒット（address を含めない）
['id' => .., 'full_name' => .., 'same_dept' => false, 'other_dept' => ['housing']]
```

`full_name` は利用者が入力した姓名と同一（同名検索のため既知）であり追加情報にならない。除外対象は住所のみ。

#### UI 変更（`buyers/_form.blade.php`）

- 他部署ヒット表示（281行付近）から**住所（`dup.address`）と他部署 show へのリンクを削除**。「○○事業に同名の顧客が登録されています」＋「この顧客を△△にも追加」ボタン＋「別人として新規登録」ボタンのみ
- 自部署ヒット表示（275-278行）は現状維持
- 検知タイミングに**市区町村（`city`）blur を追加**。姓名 blur 時点では住所未入力で他部署検知が出ないため、住所入力後にも `checkDuplicate()` が走るようにする（都道府県・市区町村の入力確定時にも発火。実装時にフィールド形式を確認し、都道府県の change ／ 市区町村の blur を選択）

#### セキュリティ上の効果

正規利用者は手元に顧客の氏名・住所を持つためヒットする。攻撃者が姓名だけを機械的に総当たりしても、住所が一致しなければ他部署はヒットせず、住所も返らない。**「住所知識」によって正規利用と総当たり攻撃が自然に分離される**。

### 3. 取込検証（V3）

`addToDepartment` に検証を追加する。

```php
$request->validate([
    'department'    => 'required|in:housing,realestate',
    'acquired_date' => 'required|date',
    'last_name'     => 'required',
    'first_name'    => 'required',
    'prefecture'    => 'required',
    'city'          => 'required',
]);

abort_unless(
    $buyer->last_name  === $request->input('last_name')
    && $buyer->first_name === $request->input('first_name')
    && $buyer->prefecture === $request->input('prefecture')
    && $buyer->city       === $request->input('city')
    && $buyer->departments()->exists(),   // 他部署に実在所属していること
    404
);
```

- JS の `addToDepartment`（`_form.blade.php` 409-429）に姓名・住所の送信を追加
- 効果: buyer ID を知るだけ・姓名だけでは取込めない。`checkDuplicate` で正規にヒットした（＝姓名＋住所が一致する）買主のみ取込可能

### 4. 監査ログ

`Log` ファサードで以下を記録する（既定チャンネル）。

- `checkDuplicate` で他部署ヒットが発生した時: `user_id` / 検索姓名 / ヒットした `buyer_id` / 部署
- `addToDepartment` 取込成功時: `user_id` / `buyer_id` / 取込先 `department`

内部者による不審な利用（総当たり試行・大量取込）を事後追跡できるようにする。

## テスト方針

- worktree では PHPUnit 実行不可（vendor は main repo への `--no-dev` symlink）。worktree では `php -l`・`view:cache`→`view:clear`・`route:list` で静的検証
- `buyers` テーブルは Laravel migration 管理外で SQLite テストDBに存在しない。`checkDuplicate`/`addToDepartment` の Feature テストは Buyer モデルに触れると 500 になるため限定的
- 実施可能な範囲:
  - check-duplicate ルートに `role:executive,manager` ＋ `department.access` が付与されたことの確認（`route:list -v` / ルート定義の静的確認）
  - 認可 middleware 自体の挙動は既存 `tests/Feature/Security/DepartmentAccessMiddlewareTest` のパターン（middleware/helper 直接呼び出し）に倣う
- 本番反映後はブラウザ実機で「staff は check-duplicate が 403」「住所未入力では他部署検知が出ない」「住所一致時のみ取込可」を確認

## 影響範囲

| ファイル | 変更内容 |
|----------|----------|
| `routes/web.php` | check-duplicate ルートに `role:executive,manager` ＋ `department.access` を追加 |
| `app/Http/Controllers/CustomerController.php` | `checkDuplicate`（自部署／他部署検索の分離・他部署戻り値から住所除外）、`addToDepartment`（姓名＋住所＋他部署実在の検証）、監査ログ |
| `resources/views/buyers/_form.blade.php` | 他部署ヒット表示から住所・リンク削除、`checkDuplicate` を市区町村 blur／都道府県 change でも発火、`addToDepartment` JS に姓名・住所送信を追加 |

3ファイル。新規クラス追加なし（`composer dump-autoload` 不要）。

## 考慮事項・既知のトレードオフ

- **住所表記揺れリスク**: 他部署検知を「都道府県＋市区町村の完全一致」にするため、同一人物でも housing/realestate で市区町村の表記（例: 「松山市」と「松山市道後町」）が揃っていないと他部署ヒットせず、共有取込できず二重登録される可能性がある。完全一致を緩める（市区町村の前方一致等）と総当たり耐性が下がるため、まずは完全一致で実装し、運用で取りこぼしが問題化した場合に緩和を検討する。取りこぼしの結果は「二重登録」であり、PII漏洩よりは軽微
- **内部者の総当たり**: 認可を manager 以上に締めても、manager 内部者が住所を含めて総当たりする余地は残る。住所知識の要求と監査ログで実用上の抑止とする（完全な防止は案③のトークン化が必要だが、UXコストが高く今回は採用しない）

## 本番反映

1. worktree 内で `/commit`
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only <branch>`
3. main repo で `composer install`（dev）→ `php artisan test` → `composer install --no-dev` で復元
4. `./deploy.sh`（rsync ＋ 本番で `config:cache && route:cache && view:cache`）
5. push は別途ユーザー明示指示時
