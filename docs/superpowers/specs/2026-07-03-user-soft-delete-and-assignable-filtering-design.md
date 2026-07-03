# ユーザー論理削除 & 担当者選択の assignable 統一 設計書

- 作成日: 2026-07-03
- 対象: ユーザー管理（`Admin\UserController`）+ 全部署の担当者選択（不動産 / 住宅 / DAD / 賃貸マンション / 買主 / アンケート）
- 種別: 既存機能の改修（担当者フィルタ統一）＋ 新機能（ユーザー論理削除・復元）
- 直接の先例: **Bug #12**（Buyer の SoftDeletes → 表示リレーションに `->withTrashed()` ＋ 編集画面に現在値を必ず含める）。今回と同型。

## 1. 背景・目的

経営層がユーザー管理から**無効化（status=inactive）**したユーザーが、不動産・住宅などの
「契約・更新」の**担当者選択ドロップダウン**や**一覧の絞り込みフィルタ**に依然として出てしまう。
退職者・異動者が候補に並び、誤選択や一覧ノイズの原因になっている。

本改修で以下を達成する。

1. **無効・削除ユーザーを担当者選択・絞り込みフィルタから除外**（新規登録・編集・一覧フィルタを統一）。
2. **ユーザー削除機能を新設**（論理削除）。履歴は残し、過去の契約詳細では担当者名を表示し続ける。
3. 削除ユーザーはユーザー管理一覧から隠し、status フィルタ「削除済み」から閲覧・復元できる。

## 2. 現状の整理

### 2.1 ユーザーの有効/無効 = `status`（削除機能は無い）

| 項目 | 現状 |
|---|---|
| 有効/無効 | `status`（`App\Enums\UserStatus`: `active`/`inactive`、`label()`→「有効」「無効」）。`isActive()` あり |
| 削除 | **未実装**。`users` テーブルに `deleted_at` 列なし。`User` に SoftDeletes trait なし。`UserController::destroy` なし |
| `$fillable` | `role`/`status` は除外（マスアサインメント対策で `UserController` が明示代入） |
| 既存ガード | `update`/`toggleStatus` に「自分自身の無効化・ロール変更禁止」「最後の有効な経営層の無効化・ロール変更禁止」 |
| 一覧 | `status` フィルタ対応済み・`orderByRaw("FIELD(status,'active','inactive')")`→`name` 順・`paginate(20)->withQueryString()` |

現 `users` カラム: `id, name, email, email_verified_at, password, role, status, must_change_password, last_login_at, remember_token, created_at, updated_at`。

### 2.2 担当者選択が**未フィルタ**（無効ユーザーが出る）箇所 — 15 箇所

すべて `User::orderBy('name')->get()` 系（一部 `->get(['id','name'])`）。新規/編集フォームの候補生成と一覧フィルタの選択肢生成が混在。

| ファイル | 行 |
|---|---|
| `RealEstate/ReContractController.php` | 92, 129, 271 |
| `Housing/HsContractListController.php` | 127, 200, 299 |
| `Dad/ProjectController.php` | 67, 146, 185 |
| `Mansion/ContractController.php` | 79, 139 |
| `Mansion/ParkingContractController.php` | 89, 132 |
| `CustomerController.php` | 681（買主担当） |
| `CustomerSurveyController.php` | 273（アンケート担当） |

**既にフィルタ済みの先例**: `Tenant/InquiryController.php:107,247` = `User::where('status','active')->orderBy('name')->get(['id','name'])`。
→ 今回はこれを `User::assignable()` スコープに一般化し、上記 15 箇所と先例 2 箇所をすべて `assignable()` に統一する。

### 2.3 担当者を表示に使う `belongsTo(User)` リレーション（`withTrashed` 付与の主対象）

`staff_user_id` / `assigned_to` 系（**確定 7 本**）:

| モデル:行 | 外部キー |
|---|---|
| `ReContract.php:80` | `staff_user_id` |
| `MsContract.php:45` | `staff_user_id` |
| `MsParkingContract.php:47` | `staff_user_id` |
| `DadProject.php:66` | `staff_user_id` |
| `BuyerSurvey.php:42` | `staff_user_id` |
| `Inquiry.php:82` | `assigned_to` |
| `Contract.php:115` | `assigned_to` |

`created_by`/`updated_by`/`uploaded_by`/`revised_by`/`registered_by`/`imported_by`/`changed_by`/`deleted_by` 系（**約20 — 表示箇所を実査して対象確定**）:
`HsProperty` / `HsContract` / `HsCustomOrder` / `ReProject` / `ReProcurement` / `ReProjectDrawing` / `ZealSimulation` /
`Transaction` / `InquiryHistory` / `HsPropertyFile` / `HsCustomOrderFile` / `RentRevision` / `UnitRentRevision` /
`ZealSheetImport` / `PropertyChangeLog` / `Attachment` ほか。

**Blade 直参照（リレーション経由でない履歴表示）**:
`mansion/contracts/revise.blade.php:558` / `mansion/parking-contracts/revise.blade.php:476` の `\App\Models\User::find($rev->created_by)`。

### 2.4 編集フォームの「現在担当者」パターン（`assignable ∪ 現在担当` の対象）

例: `realestate/contracts/edit.blade.php:117,142`
`<option ... {{ old('staff_user_id', $contract->staff_user_id) == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>`
→ 候補を `assignable` に絞ると、現在担当が無効/削除済みのとき option 自体が消え、**編集保存で担当者が飛ぶ**（Bug #12 と同型）。

一覧の絞り込みフィルタ例: `realestate/contracts/index.blade.php:90` / `housing/contracts/index.blade.php:120`（`request('staff_user_id')`）。

### 2.5 テスト実行環境（テスト計画に直結）

- Feature テストは **SQLite in-memory + `RefreshDatabase`**（`phpunit.xml`: `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`）。
- スキーマは **Laravel migration から構築**される。`users` は `database/migrations/0001_01_01_000000_create_users_table.php` 管理で、
  現状 **`softDeletes()` を持たない**。
- raw SQL 専用テーブル（zeal 等）はテスト用に `tests/Concerns/CreatesZealSchema.php` で `Schema::create` している。

## 3. 決定事項（確定 — 前セッション brainstorming 結論）

| # | 論点 | 決定 |
|---|---|---|
| D1 | 削除方式 | **論理削除（SoftDeletes）**。履歴は残す。過去の契約詳細では「田中（削除済み）」のように担当者名を表示し続ける |
| D2 | 一覧での扱い | 削除ユーザーは一覧から隠す。status フィルタに**「削除済み」を追加**し、そこから閲覧・復元できる |
| D3 | フィルタ統一 | 各一覧の担当者**絞り込みフィルタ**も無効・削除を除外して統一。新規登録・編集の担当者選択からの除外は当然 |
| D4 | 削除ガード | 既存の無効化ガードと**対称**: **自分自身は削除不可** / **最後の有効な経営層は削除不可** |
| D5 | 復元 | status フィルタ「削除済み」の行から**復元**（`deleted_at` を null 化）できる |

## 4. 実装設計

方針は **D1〜D5 を満たす最小差分**。既存の `toggleStatus` ガードと `orderByRaw` 表示順を踏襲し、
担当者選択は `scopeAssignable` に一本化する。

### 4.1 データ層（`deleted_at` 追加）— **2 系統に書く**（重要）

live DB は raw SQL 管理、テスト DB は migration 構築のため、**両方**に `deleted_at` を入れる。片方だけだと以下で破綻する。

- raw SQL のみ → テスト DB（SQLite）に列が無く、SoftDeletes テストが全滅。
- migration のみ → live DB（`masa8787kanri63732` / 本番）は migration を流さない運用のため列が入らず、本番 500。

| 対象 | 変更 | 適用 |
|---|---|---|
| `database/sql/2026-07-03-add-deleted-at-to-users.sql`（新規） | `ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;` | ローカル: `sudo mysql masa8787kanri63732 < ...`／本番: 同 SQL を ssh 実行（**要ユーザー明示承認**・csh） |
| `database/migrations/0001_01_01_000000_create_users_table.php` | `$table->timestamps();` の直後に `$table->softDeletes();` を追加 | `RefreshDatabase`（テスト）と新規 `migrate` のみ反映。既存 live DB は再実行されない（安全） |

> 補足: live DB は raw SQL が正。base migration を編集しても既に実行済み環境では re-run されないため live に影響しない。migration 編集は「テスト用スキーマ定義の更新」として扱う。

`app/Models/User.php`:
- `use Illuminate\Database\Eloquent\SoftDeletes;` を追加、`use HasFactory, Notifiable, SoftDeletes;`。
- `casts()` に `'deleted_at' => 'datetime'`（任意・SoftDeletes が面倒を見るが明示すると一貫）。

### 4.2 担当者選択の統一（`scopeAssignable`）

`User` に scope を新設。SoftDeletes のグローバルスコープが削除済みを自動除外するので、**status=active のみ明示**でよい。

```php
// app/Models/User.php
public function scopeAssignable($query)
{
    // 担当者として選択可能なユーザー = 有効かつ未削除。
    // 削除済みは SoftDeletes のグローバルスコープが自動的に除外する。
    return $query->where('status', UserStatus::Active->value);
}
```

置換対象（2.2 の 15 箇所 + 先例 2 箇所）を機械的に統一:
- `User::orderBy('name')->get()` → `User::assignable()->orderBy('name')->get()`
- `User::orderBy('name')->get(['id','name'])` → `User::assignable()->orderBy('name')->get(['id','name'])`
- `Tenant/InquiryController.php:107,247` の `User::where('status','active')...` → `User::assignable()...`（意味等価・表現統一）

一覧の**絞り込みフィルタ選択肢**を生成しているコントローラも同じ `assignable()` に合わせる（フィルタと候補で母集団を一致させる）。

### 4.3 編集画面での現在担当者の保持（最重要・Bug #12 対策）

編集フォームのドロップダウン母集団を **`assignable ∪ 現在の担当者`** にする。現在担当が無効/削除済みでも option を必ず残す。

コントローラ（edit アクション）での候補生成例:

```php
$assignable = User::assignable()->orderBy('name')->get(['id', 'name']);

// 現在の担当者が assignable に無ければ（無効化 or 削除済み）候補へ追加。
// 削除済みも拾えるよう withTrashed で取得する。
$current = $contract->staff_user_id
    ? User::withTrashed()->find($contract->staff_user_id)
    : null;
if ($current && ! $assignable->contains('id', $current->id)) {
    $staffUsers = $assignable->push($current)->sortBy('name')->values();
} else {
    $staffUsers = $assignable;
}
```

Blade 側で現在担当の状態を注記（`selected` 判定は既存のまま）:
- 無効: `田中（無効）` … `$su->status === UserStatus::Inactive`
- 削除済み: `田中（削除済み）` … `$su->trashed()`（`deleted_at !== null`）

> option ラベルの注記は Blade で `@if($su->trashed()) （削除済み）@elseif(...)（無効）@endif` を名前の後ろに連結する。
> `withTrashed()->find()` が必要（既定の `find()` は SoftDeletes グローバルスコープで削除済みを null 返しするため）。

**受け入れ基準**: 無効/削除済みの担当が付いた契約を編集 → 担当者を触らず保存 → 担当者が保持されること（飛ばない）。

### 4.4 過去レコードの担当者名表示（最重要・`withTrashed`）

`User` に SoftDeletes を入れると、既定の `belongsTo(User)` は削除済みを **null** で返し、詳細画面で担当者名が消える。
**表示に使うリレーションに `->withTrashed()` を付与**して履歴を残す。

- **確定対象（2.3 の 7 本）**: `ReContract::staff` / `MsContract::staff` / `MsParkingContract::staff` / `DadProject::staff` /
  `BuyerSurvey::staff` / `Inquiry::assignee` / `Contract::assignee`（メソッド名は各モデルの実名に合わせる）に `->withTrashed()` を付ける。
  ```php
  return $this->belongsTo(User::class, 'staff_user_id')->withTrashed();
  ```
- **`created_by`/`updated_by` 系（約20）**: 「その名前を UI に表示しているか」を実査して確定。
  履歴・監査表示（登録者/更新者/改定者/取込者）に出しているリレーションのみ `withTrashed` を付ける。表示していない監査カラムは対象外でよい。
  - 洗い出し: `grep -rn "belongsTo(User" app/Models/` でリレーションを列挙 → 各メソッド名を Blade で `grep` し、名前表示の有無を確認。
- **Blade 直参照（2.3）**: `mansion/contracts/revise.blade.php:558` と `mansion/parking-contracts/revise.blade.php:476` の
  `User::find($rev->created_by)` を `User::withTrashed()->find($rev->created_by)` に修正（削除済みを null 返ししないため）。

> 表示ラベルに「（削除済み）」を必ず付けるかは UI 判断。契約詳細の履歴では素の名前でも可、担当者フィールドとして目立つ箇所は 4.3 と同様に注記を検討（writing-plans で表示箇所ごとに決定）。

### 4.5 ユーザー削除・復元機能

**ルート**（`routes/web.php:75` の `Route::middleware('role:executive')->prefix('admin')` グループ内、`toggleStatus`（85 行）の隣に追加）:

| メソッド | パス | 名前 | 権限 |
|---|---|---|---|
| DELETE | `/admin/users/{user}` | `admin.users.destroy` | グループ継承 = **`role:executive`（経営層のみ）** |
| PATCH | `/admin/users/{user}/restore` | `admin.users.restore` | 同上。**ルートに `->withTrashed()` 必須**（削除済みを解決するため） |

> `restore` の route model binding は既定で soft-deleted を除外し 404 になる。
> `Route::patch('/users/{user}/restore', ...)->name('admin.users.restore')->withTrashed();` とする。
> `update`/`toggleStatus`/`resetPassword`（既定 binding）は soft-deleted を弾くが、削除済みは「復元してから編集」の運用で問題ない。
> グループ冒頭コメント「システム管理（10ルート）」の件数は 2 本追加に合わせて更新する。

**`UserController::destroy(User $user)`** — 論理削除。ガードは `toggleStatus` と対称:

```php
// 自分自身は削除不可
if ($user->id === auth()->id()) {
    return redirect()->route('admin.users.index')
        ->with('error', '自分自身を削除することはできません。');
}
// 最後の有効な経営層は削除不可（無効化ガードと同ロジック）
if ($user->role === UserRole::Executive && $user->status === UserStatus::Active) {
    $otherActiveExecutives = User::where('id', '!=', $user->id)
        ->where('role', UserRole::Executive->value)
        ->where('status', UserStatus::Active->value)
        ->count();
    if ($otherActiveExecutives === 0) {
        return redirect()->route('admin.users.index')
            ->with('error', '唯一の有効な経営層ユーザーは削除できません。');
    }
}
$user->delete(); // SoftDeletes → deleted_at をセット
return redirect()->route('admin.users.index')
    ->with('success', "ユーザー「{$user->name}」を削除しました。削除済み一覧から復元できます。");
```

**`UserController::restore(User $user)`** — 復元（`{user}` はルートで `withTrashed()` 解決済み）:

```php
$user->restore(); // deleted_at を null 化。status は変えない（削除前の値を維持）
return redirect()->route('admin.users.index')
    ->with('success', "ユーザー「{$user->name}」を復元しました。");
```

**一覧（`index`）の status フィルタに「削除済み」を追加**（D2）。`deleted` は `UserStatus` の値ではないため特別分岐:

```php
if ($request->status === 'deleted') {
    $query = User::onlyTrashed()->with('departments');   // 削除済みのみ
} else {
    $query = User::with('departments');                  // 既定は未削除（SoftDeletes 自動除外）
    if (in_array($request->status, [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
        $query->where('status', $request->status);
    }
}
// 以降のロール/部門/検索フィルタ・orderByRaw・paginate は共通のまま
```

**Blade（`admin/users/index.blade.php`）**:
- status `<select>`（現状 79–82 行が `UserStatus` cases をループ）に、ループ外で `<option value="deleted">削除済み</option>` を追加。
- 各行のアクション（編集モーダル / 無効化・有効化ボタンの並び）に **削除ボタン**を追加 → 確認モーダル（既存の無効化確認モーダル 369 行付近を複製）→ `DELETE admin.users.destroy`。自分自身の行では非表示。
- 「削除済み」表示時は行に **復元ボタン**（`PATCH admin.users.restore`）を出し、編集/無効化/削除は隠す。
- 削除済み行のステータスバッジは「削除済み」を灰系の inline style で表示（`badgeStyle` 相当は無いので inline）。

## 5. テスト計画（回帰防止）

`tests/Feature/Admin/UserSoftDeleteTest.php`（新規、`RefreshDatabase`）。4.1 の migration `softDeletes()` 追加が前提。

| # | 検証 |
|---|---|
| T1 | **担当者選択に無効・削除が出ない**: active / inactive / trashed の 3 ユーザーを用意し、`User::assignable()->get()` が active のみを返す |
| T2 | **編集で現在の無効担当者が保持**: 無効ユーザーを担当に持つ契約の edit で候補に当該ユーザーが含まれ、担当据え置き update 後も `staff_user_id` が不変 |
| T3 | **削除ユーザーの過去レコードで名前表示（withTrashed）**: 担当を論理削除後、詳細で `$model->staff->name` が取得できる（null にならない） |
| T4 | **削除ガード（自分）**: 自分自身の destroy が拒否され `deleted_at` が null のまま |
| T5 | **削除ガード（最後の経営層）**: 有効経営層が 1 人のとき destroy 拒否。2 人目がいれば成功 |
| T6 | **削除→復元の往復**: destroy で一覧（既定）から消え `onlyTrashed` に出る → restore で `deleted_at` が null に戻り既定一覧へ復帰 |
| T7 | **一覧フィルタ**: `status=deleted` で削除済みのみ、既定で未削除のみが返る |

> 契約系モデルを使う T2/T3 は、対象テーブルが Laravel migration 管理か raw SQL 管理かで seed 方法が変わる（raw SQL 系は `Schema::create` トレイト方式）。
> 最小構成として **`Inquiry`（assigned_to）または `ReContract`（staff_user_id）** のうち migration 管理側を代表に据える。詳細は writing-plans で確定。

## 6. デプロイ・検証手順（本番反映）

1. worktree で実装 → `/commit`（1 コミット 1 関心事）。
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only <branch>`。
3. **新規 PHP クラスは無い**想定（scope/method 追加のみ）。追加した場合のみ main repo cwd で `composer dump-autoload`。
4. **DB 反映**: ローカル `sudo mysql masa8787kanri63732 < database/sql/2026-07-03-add-deleted-at-to-users.sql`。
   本番は同 SQL を ssh 実行（**要ユーザー明示承認**・csh・`$()`/`~`/`$HOME` 不可）。**`./deploy.sh` は SQL を流さない**ため別途必須。
5. **Blade 検証（Bug #26 対策）**: `view:cache` 成功だけで判断せず、コンパイル済みビューを lint:
   ```
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
6. **実データ検証**: 無効/削除担当が付いた契約の詳細・編集を実データでレンダリング（空ローカルで素通りする本番 500 = Bug #22/#25 を回避）。
7. `./deploy.sh`（rsync + 本番 config/route/view:cache 再生成）。
8. origin/13.x への push はユーザー明示指示時のみ。

## 7. スコープ外（今回やらないこと）

- **物理削除（forceDelete）**の導線。UI からは論理削除のみ。
- `created_by`/`updated_by` 系で**画面に表示していない**監査カラムへの `withTrashed` 付与（表示のあるものだけ対象）。
- ユーザー削除に伴う**担当契約の一括付け替え**（削除は担当割当を変えない。履歴として担当名を残すのが D1 の趣旨）。
- `UserStatus` enum への `Deleted` ケース追加（削除は `deleted_at` で表現し、status とは直交させる）。

## 8. リスク・留意点

- **restore の 404 落とし穴**: `restore` ルートに `->withTrashed()` を付け忘れると、削除済み `{user}` が binding で解決されず常に 404。実装時に明示テスト（T6）。
- **テスト DB の列欠落**: migration に `softDeletes()` を入れ忘れると SoftDeletes テストが「no such column: deleted_at」で全滅。4.1 の 2 系統書き込みを厳守。
- **担当者が飛ぶ回帰（Bug #12）**: `assignable` 化で編集候補から現在担当が消えると保存で担当が飛ぶ。4.3 の `assignable ∪ 現在担当` を全編集画面に適用し T2 で担保。
- **`User::find()` の暗黙除外**: SoftDeletes 導入後、`User::find($id)` は削除済みを null 返し。Blade 直参照（2.3）や既存コードで担当名を出している箇所は `withTrashed()->find()` に要修正。横展開: `grep -rn "User::find(" app/ resources/`。
- **`orderByRaw("FIELD(status,...)")` と削除済み**: `status=deleted` 分岐は `onlyTrashed()` の別クエリなので FIELD 順の影響は無いが、既定一覧は従来どおり active→inactive 順を維持する。
- **フィルタと候補の母集団一致**: 一覧フィルタの選択肢も `assignable` にしないと、「フィルタに無効者が出るが候補には出ない」等の不整合が残る。4.2 で両方を統一。
- **削除ユーザーのログイン不可（副作用・想定内）**: SoftDeletes のグローバルスコープは認証プロバイダの `retrieveByCredentials` にも効くため、削除ユーザーは**ログイン不可**になり、既存セッションも次リクエストで解決されず実質ログアウトされる。これは「削除」の意味として妥当（無効化＝ログイン不可の上位互換）。ただし**自分自身は削除不可**（D4）なので操作者が自分を締め出す事故は起きない。
