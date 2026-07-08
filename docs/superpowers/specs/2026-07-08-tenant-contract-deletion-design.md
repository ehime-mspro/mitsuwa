# テナント契約の削除機能 設計書

- 作成日: 2026-07-08
- 対象: テナント管理 契約（`Tenant\ContractController`）
- 種別: 新機能（誤登録した契約の取消・削除）
- 直接の先例:
  - **解約処理 `terminate`**（`ContractController::showTerminate`/`terminate`）— 「確認画面 → 実行」の 2 段階・トランザクション内で区画ステータスを戻す構造を踏襲。
  - **ユーザー論理削除**（`docs/superpowers/specs/2026-07-03-user-soft-delete-and-assignable-filtering-design.md`）— SoftDeletes + `role:executive` 限定 + 削除確認 UI の同型。
  - **Bug #12**（SoftDeletes モデルの表示リレーションは `withTrashed`）。

## 1. 背景・目的

テナント管理で**間違えて追加した契約**を取り消す手段が無い。現状 `ContractController` に `destroy` は無く、
一度登録した契約は解約（terminated 化）しかできず、誤登録データが一覧・詳細・集計に残り続ける。

本機能で以下を達成する。

1. 経営層が誤登録した契約を**論理削除**できる（一覧・詳細から見えなくなる。データは DB に残る）。
2. 削除に伴い、契約登録時の**副作用を巻き戻す**（区画を空室に戻す・問合せ連携を解除して未成約に戻す・投資案件の紐付けを解除）。
3. 削除は破壊的操作のため、**専用確認画面**で関連データ件数を警告してから実行する。

## 2. 決定事項（確定）

前セッション brainstorming で確定した要件（D1〜D5）＋ 本セッションでユーザー確認した設計分岐（D6〜D7）。

| # | 論点 | 決定 |
|---|---|---|
| D1 | 削除方式 | **論理削除（SoftDeletes）**。Contract は既に SoftDeletes trait 済み。**復元 UI は不要**（必要時は DB で対応） |
| D2 | 権限 | **経営層（executive）のみ** |
| D3 | 削除範囲 | **契約中（active）・解約済み（terminated）の両方** |
| D4 | 関連データ（投資・賃料改定・問合せ・添付） | **警告表示のうえ削除可**。関連データ自体は物理削除しない |
| D5 | 確認 UI | **専用確認画面**（解約 terminate と同じ「確認画面 → 実行」の 2 段階） |
| D6 | 問合せの成約ステータス | **未成約に戻す**。`contract_id` を解除し、`status=Converted` を **`Follow`（フォロー）** に差し戻す。InquiryHistory に解除履歴を記録 |
| D7 | 投資案件 | **投資レコードは区画に残す**。契約との紐付け（`investment.contract_id`）のみ null 化 |

## 3. 現状の整理（検証済みのコード事実）

### 3.1 Contract は既に SoftDeletes・DB 変更不要

- `Contract` は `use HasFactory, SoftDeletes;`（`app/Models/Contract.php:18`）。`contracts` テーブルは migration 管理で `deleted_at` を保有（`database/migrations/0001_01_01_000007_create_contracts_table.php`）。
- → **本機能は DB スキーマ変更ゼロ**。マイグレーションも raw SQL も不要（前回 User spec の「2 系統書き込み」問題は発生しない）。
- ルートモデルバインディング `{contract}` は `getRouteKeyName()`/`resolveRouteBinding()` の override 無し・ルートに `->withTrashed()` 無し → **削除済み契約は自動的に 404**。削除後の `show`/`edit`/`terminate`/`revise` は URL 直打ちでも到達不可（安全）。

### 3.2 権限判定（ボタン出し分け・ルート保護）

- `User::isExecutive()`（`app/Models/User.php:90`）= `role === UserRole::Executive`。`UserRole::Executive`（`app/Enums/UserRole.php:7`）存在。
- ミドルウェア `role:executive` は既に**賃料改定ルート**で使用中（`routes/web.php:283`）。削除ルートは同グループパターンをそのまま流用する。
- Blade は既存 `show.blade.php:272` が `auth()->user()->role->isExecutive()` を使用 → 削除ボタンも同じ書式で統一。

### 3.3 巻き戻し対象＝`store()` の副作用（`ContractController`）

`store()`（`app/Http/Controllers/Tenant/ContractController.php:121-224`）がトランザクション内で行う副作用と、その逆操作:

| store の副作用 | 該当行 | 削除時の逆操作 |
|---|---|---|
| 区画を `Occupied` に更新 | `192` | **active だった場合のみ** `Vacant` に戻す（`terminate` の `410` と同じ） |
| 問合せ連携（`linkInquiry`）: `inquiry.contract_id=契約id` / `status=Converted` / `result_reason='契約登録に伴い成約'`（空時）/ InquiryHistory 記録 | `194-197`, `696-726` | `contract_id=null` / `Converted→Follow` / 自動 `result_reason` をクリア / InquiryHistory に解除記録（D6） |

> `terminate()`（`370-430`）は区画を `Vacant` に戻す（`410`）。削除の区画巻き戻しはこれと同一。

### 3.4 投資案件・賃料収入は自動除外（改変不要）

- **Investment**: `calculateRecovery()`（`app/Models/Investment.php:94-107`）は `Contract::where('unit_id', ...)` の **unit_id ベース**・ライブ計算。`contract_id` は回収計算に**不使用**（紐付けメタデータ）。契約を論理削除すれば SoftDeletes グローバルスコープで自動除外され、回収表示から自動的に外れる。→ **`Investment.php` の改変は不要**。D7 の `contract_id` null 化は整合性のためのみ（回収計算には無影響）。
  - ⚠ `investments.contract_id` は `->constrained('contracts')->nullOnDelete()`（`create_investments_table.php:27`）だが、`nullOnDelete` は **物理 DELETE 時のみ**発火し **論理削除（`deleted_at` の UPDATE）では効かない**。そのため `destroy()` で **明示的に null 化する**必要がある（D7 の `Investment::where(...)->update()` はこの理由。DB 制約に頼れない）。
- **RentalIncomeService**: `forUnit()`/`forProperty()`（`app/Services/Tenant/RentalIncomeService.php:18,28`）は `Contract::where(...)->get()` で `withTrashed` 無し → 論理削除で自動除外。→ **改変不要**。

### 3.5 関連モデルのカラム（逆操作に使用）

- `Inquiry`（`app/Models/Inquiry.php`）: `contract_id` / `result_reason` / `status`（`InquiryStatus` cast）が fillable。`Contract` に `inquiries()` リレーションは無いため、削除時は `Inquiry::where('contract_id', $contract->id)` で取得。
- `InquiryStatus`（`app/Enums/InquiryStatus.php`）: `Follow`/`OnHold`/`Converted`/`Lost`/`Unreachable`。成約前の元ステータス（Follow か OnHold か）は**記録されていない**ため、差し戻し先は一律 `Follow`。
- `Investment`: `contract_id` fillable。`Investment::where('contract_id', ...)` で紐付けを特定。
- `UnitStatus`（`app/Enums/UnitStatus.php`）: `Occupied`/`Vacant`/`Negotiating`。差し戻しは `Vacant`。

## 4. 実装設計

方針は **既存 `terminate` の構造を踏襲した最小差分**。変更は 4 ファイル（うち 1 新規）。DB 変更なし・新規 PHP クラスなし。

### 4.1 ルート（`routes/web.php`）

STEP 6 契約ブロックの賃料改定グループ直後（`routes/web.php:288` の後）に、`role:executive` グループを新設:

```php
// 契約削除（経営層のみ・契約中/解約済みの両方）
Route::middleware('role:executive')->group(function () {
    Route::get('/contracts/{contract}/delete', [\App\Http\Controllers\Tenant\ContractController::class, 'confirmDelete'])
        ->name('tenant.contracts.delete');
    Route::delete('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'destroy'])
        ->name('tenant.contracts.destroy');
});
```

- ブロック冒頭コメント「テナント契約管理（10ルート）」（`routes/web.php:246`）を **12ルート** に更新。
- `{contract}` は既定 binding のまま（`withTrashed` を**付けない**）＝削除済みは 404。

### 4.2 `ContractController::confirmDelete(Contract $contract)`（確認画面）

削除確認画面を表示。関連データ件数を渡して警告表示する。**多行配列を `@json` に渡さない**ため、件数はスカラー変数で個別に渡す（Bug #26 回避）。

```php
public function confirmDelete(Contract $contract)
{
    $contract->load(['property', 'unit', 'customer']);

    $relatedInquiryCount   = Inquiry::where('contract_id', $contract->id)->count();
    $hasInvestment         = Investment::where('contract_id', $contract->id)->exists();
    $rentRevisionCount     = $contract->rentRevisions()->count();
    $attachmentCount       = $contract->attachments()->count();

    return view('tenant.contracts.delete', compact(
        'contract', 'relatedInquiryCount', 'hasInvestment', 'rentRevisionCount', 'attachmentCount'
    ));
}
```

### 4.3 `ContractController::destroy(Contract $contract)`（削除実行）

トランザクション内で D6/D7 の巻き戻し → 論理削除。処理順は「関連解除 → 区画 → 契約削除」。

```php
public function destroy(Contract $contract)
{
    $wasActive = $contract->isActive();

    DB::transaction(function () use ($contract, $wasActive) {
        // ① 問合せ連携の解除（未成約に差し戻し・D6）
        $inquiries = Inquiry::where('contract_id', $contract->id)->get();
        foreach ($inquiries as $inquiry) {
            $inquiry->contract_id = null;
            if ($inquiry->status === InquiryStatus::Converted) {
                $inquiry->status = InquiryStatus::Follow->value;
                // 契約登録時に自動設定した理由のみクリア（手動入力の理由は残す）
                if ($inquiry->result_reason === '契約登録に伴い成約') {
                    $inquiry->result_reason = null;
                }
            }
            $inquiry->save();

            InquiryHistory::create([
                'inquiry_id'  => $inquiry->id,
                'action_type' => 'other',
                'action_date' => now()->toDateString(),
                'content'     => '契約 ' . $contract->contract_number . ' の削除に伴い連携解除（未成約に差し戻し）',
                'created_by'  => Auth::id(),
            ]);
        }

        // ② 投資案件の紐付け解除（投資レコードは区画に残す・D7）
        Investment::where('contract_id', $contract->id)->update(['contract_id' => null]);

        // ③ 契約中だった場合のみ区画を空室に戻す（terminated は既に Vacant のため触らない）
        if ($wasActive) {
            $contract->unit->update(['status' => UnitStatus::Vacant->value]);
        }

        // ④ 契約を論理削除
        $contract->delete();
    });

    return redirect()
        ->route('tenant.contracts.index')
        ->with('success', "契約「{$contract->contract_number}」を削除しました。");
}
```

設計上の要点:
- **`$wasActive` ガード必須**: 解約済み契約の区画は既に `Vacant`、かつ**その後に別の契約で同区画が `Occupied`** になっている可能性がある。terminated 契約削除で区画を触ると現入居者を誤って空室化する → active のときのみ戻す。
- `Investment::where(...)->update()` は該当 0 件でも安全。SoftDeletes グローバルスコープで未削除の投資のみ対象。
- 添付ファイル（`attachments` morphMany）は**物理削除しない**。論理削除では storage 上の実体も DB レコードも残す（復元時に添付も戻る前提）。
- `use` 済み import（`Inquiry`/`InquiryHistory`/`Investment`/`InquiryStatus`/`UnitStatus`/`DB`/`Auth`）は既に `ContractController` 先頭にあり追加不要。

### 4.4 削除ボタン（`resources/views/tenant/contracts/show.blade.php`）

**解約済みでも表示**するため、既存の下部アクション群 `@if($contract->isActive())`（`show.blade.php:263-280`）の**外**に、独立した `role:executive` 限定ブロックを追加（`280` 行の `@endif` 直後）:

```blade
{{-- 契約削除（経営層のみ・契約中/解約済み問わず） --}}
@if(auth()->user()->role->isExecutive())
    <div class="flex flex-wrap gap-2 mb-4">
        <a href="{{ route('tenant.contracts.delete', $contract) }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-red-300 rounded-md text-sm font-semibold text-red-700 hover:bg-red-50 transition-colors">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            契約を削除
        </a>
    </div>
@endif
```

- クラスは既存の解約ボタン（`red-200`）より一段強い `red-300`/`red-700` で危険度を表現。使用クラスはすべて RULES.md「Working Tailwind Classes」内 or 既存ボタンで実績あり。
- 破壊的操作なので編集ボタン（ヘッダー右上）とは分離し、下部アクション群に単独配置（誤クリック防止）。

### 4.5 確認画面 `resources/views/tenant/contracts/delete.blade.php`（新規）

`terminate.blade.php` のレイアウトを参考に、単純な確認画面として作る。**Alpine/@json は使わない**（Bug #23/#26 回避）。

構成:
1. **契約要点カード**: 契約番号・ステータスバッジ・物件名・区画名・顧客（店舗名）・月額家賃。
2. **関連データ警告**（`confirmDelete` から渡る件数をスカラーで表示）:
   - 紐づく投資案件: `@if($hasInvestment)` あり `@else` なし
   - 賃料改定履歴: `{{ $rentRevisionCount }}` 件
   - 紐づく問合せ: `{{ $relatedInquiryCount }}` 件
   - 添付ファイル: `{{ $attachmentCount }}` 件
3. **注記**（削除の意味を明示）:
   - この操作は論理削除です。データは DB に残り、一覧・詳細からは見えなくなります（復元が必要な場合は管理者に連絡）。
   - `@if($contract->isActive())` 契約中のため、区画「{{ 区画名 }}」は**空室に戻ります**。
   - 紐づく問合せは**未成約（フォロー）に差し戻され**ます。
   - 投資案件は区画に残り、この契約との紐付けのみ解除されます。
4. **DELETE フォーム** + キャンセル:
   ```blade
   <form method="POST" action="{{ route('tenant.contracts.destroy', $contract) }}"
         onsubmit="return confirm('本当にこの契約を削除しますか？');">
       @csrf
       @method('DELETE')
       <button type="submit" ...>削除する</button>
   </form>
   <a href="{{ route('tenant.contracts.show', $contract) }}" ...>キャンセル</a>
   ```
- 動的 route 属性に `&quot;` を使わない（Bug #21）。route 名は静的文字列（`'tenant.contracts.destroy'`）でシングルクォート。
- 二重送信対策の `onsubmit confirm` はネイティブ `confirm()`（Alpine 不要）。

## 5. テスト計画（`tests/Feature/Tenant/ContractDeletionTest.php` 新規・RefreshDatabase）

contracts/investments/inquiries はテスト DB（SQLite）でも migration 構築されるため、SoftDelete テストが書ける。seed パターンは既存 `tests/Feature/Tenant/ContractReviseEntryTest.php` を踏襲。

| # | 検証 |
|---|---|
| T1 | **active 契約削除**: executive で `DELETE` → 契約が論理削除（`trashed()` true・既定一覧から消え `onlyTrashed` に出る）、区画が `Occupied`→`Vacant` |
| T2 | **terminated 契約削除**: 解約済み契約を削除 → 論理削除される。**区画ステータスは変更されない**（`$wasActive` ガードの担保） |
| T3 | **問合せ差し戻し（D6）**: `contract_id` 紐付き・`status=Converted` の問合せがある契約を削除 → 当該 inquiry の `contract_id=null` / `status=Follow`、InquiryHistory が 1 件追加、自動 `result_reason` がクリア |
| T4 | **投資の紐付け解除（D7）**: `contract_id` を持つ投資がある契約を削除 → `investment.contract_id=null` かつ投資レコードは**残る**（`trashed()` false） |
| T5 | **権限ガード**: manager / staff は `GET /delete`・`DELETE` とも **403**（`role:executive` ミドルウェア） |
| T6 | **削除後 404**: 削除した契約の `show`/`edit`/`terminate` に GET → **404**（ルートモデルバインディング soft-delete 除外） |
| T7 | **回収・賃料集計から自動除外**（任意）: 削除済み契約が `Investment::calculateRecovery()` / `RentalIncomeService` の集計に含まれない |

> `inquiry_histories`（`2026_03_28_000004_create_inquiry_histories_table.php`）は **migration 管理を確認済み**。`inquiries` も同系統（テナント STEP 系 migration）で SQLite テスト DB に構築される想定 → T3 実行可能。`InquiryHistory` fillable は `inquiry_id/action_type/action_date/content/created_by`（`action_type` は `string(50)`・enum 制約なし）で、4.3 の `InquiryHistory::create` と完全一致。

## 6. デプロイ・検証手順（本番反映）

1. worktree（`.claude/worktrees/<name>`）で実装 → `/commit`（1 コミット 1 関心事）。
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only <branch>`。
3. **新規 PHP クラスなし**（メソッド追加のみ）→ `composer dump-autoload` **不要**。
4. **DB 変更なし**（Contract は既に SoftDeletes）→ SQL 実行 **不要**。
5. **Blade 検証（Bug #26 対策・必須）**: `view:cache` 成功だけで判断せず、コンパイル済みビューを lint:
   ```
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
6. **実データ検証**: 投資・問合せ・添付が紐づく active 契約と、terminated 契約それぞれで確認画面 → 削除を実行し、区画ステータス・問合せ差し戻し・投資紐付け解除・404 を確認（空ローカルで素通りする本番 500 を回避）。
7. `./deploy.sh`（rsync + 本番 `config:cache && route:cache && view:cache` 再生成）。**要ユーザー明示承認**。
8. origin/13.x への push はユーザー明示指示時のみ。

## 7. スコープ外（今回やらないこと）

- **物理削除（forceDelete）**の導線。UI からは論理削除のみ。
- **復元 UI**（D1 で不要と決定。必要時は DB で `deleted_at` を null 化）。
- 削除済み契約の**一覧表示**（`onlyTrashed` フィルタ）。
- 問合せ差し戻し先の**厳密復元**（元が Follow か OnHold かは未記録のため一律 Follow）。
- 投資案件・賃料改定履歴・添付ファイルの**物理削除**（区画・契約に残す）。
- 他部署契約（Mansion / Housing / RealEstate）の削除機能（本 spec はテナント契約のみ）。

## 8. リスク・留意点

- **terminated 契約削除で区画を触らない**（最重要）: `$wasActive` ガードを外すと、解約後に同区画へ入居した**現契約者を誤って空室化**する。T2 で担保。
- **問合せ差し戻しの不正確さ（許容）**: 元ステータス（Follow/OnHold）が未記録のため一律 `Follow`。また `linkInquiry` は「元から Converted の問合せは status 変更をスキップ」するため、契約と無関係に成約扱いだった問合せも `Follow` に戻り得る。`contract_id` 紐付きの問合せは当該契約経由の成約が大半のため実務上許容。注記を確認画面に出す。
- **ルートモデルバインディング**: 削除ルートに `->withTrashed()` を**付けない**（付けると削除済みも解決でき二重削除等の穴）。削除後 404 は仕様。
- **Bug #26（@json 多行配列）**: `delete.blade.php` では件数を個別スカラーで渡し、`@json` に多行配列を渡さない。検証は `view:cache` 成功で満足せず `php -l` ループ必須。
- **Bug #21（属性内 &quot;）**: 確認画面の route 属性は静的シングルクォート。動的連結・`&quot;` を使わない。
- **Bug #22（cast 済み enum に tryFrom）**: 本実装では `tryFrom` を使わず、cast 済み enum は直接比較（`$inquiry->status === InquiryStatus::Converted`）。
- **添付の実体ファイル**: 論理削除では storage 上のファイルは残る（意図通り）。ディスク肥大が気になる場合は将来別途 forceDelete 導線で対応（スコープ外）。
- **二重契約防止との整合**: active 契約削除 → 区画 `Vacant` → 同区画に再契約可能。正しい挙動（誤登録の取消後に正しい契約を登録できる）。
