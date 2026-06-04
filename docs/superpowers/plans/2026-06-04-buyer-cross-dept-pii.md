# 買主部署横断PII対策 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 買主マスタの部署共有フロー（重複検知→他部署買主の自部署取込）を維持しつつ、認可欠如（V1）・他部署PII露出（V2）・任意ID取込（V3）を塞ぐ。

**Architecture:** 案①（一致厳密化＋取込検証）。check-duplicate ルートを登録と同じ認可ライン（`role:executive,manager` ＋ `department.access`）に揃え、checkDuplicate を「自部署検索（姓名一致）」と「他部署検索（姓名＋都道府県＋市区町村の完全一致）」に分離し他部署戻り値から住所を除外、addToDepartment に姓名＋住所＋他部署実在の検証を追加、UI から他部署PII表示を削除。

**Tech Stack:** Laravel 12 / Blade + Alpine.js 3 / MySQL（`buyers` ＋ `buyer_departments` ピボット）

**テスト制約（重要）:** worktree では PHPUnit 実行不可（vendor は main repo への `--no-dev` symlink）。`buyers` テーブルは Laravel migration 管理外で SQLite テストDBに存在しないため、checkDuplicate/addToDepartment の Feature テストは書けない。各タスクは `php -l`（PHP構文）・`php artisan view:cache`→`view:clear`（Blade構文）・`php artisan route:list`（認可）で静的検証し、セキュリティ挙動は Task 5 の本番ブラウザ実機で確認する。

---

### Task 1: check-duplicate ルートの認可強化（V1）

**Files:**
- Modify: `routes/web.php:1428-1430`

- [ ] **Step 1: ルートに認可 middleware を追加**

現状（1428-1430）:
```php
    // 重複チェックAjax
    Route::post('/api/customers/check-duplicate', [\App\Http\Controllers\CustomerController::class, 'checkDuplicate'])
        ->name('api.customers.check-duplicate');
```

変更後:
```php
    // 重複チェックAjax（登録と同じ認可ライン: 経営層+管理者 ＋ 自部署所属）
    Route::post('/api/customers/check-duplicate', [\App\Http\Controllers\CustomerController::class, 'checkDuplicate'])
        ->middleware('role:executive,manager')
        ->middleware('department.access')
        ->name('api.customers.check-duplicate');
```

`department.access`（引数なし）は JSON body の `department` を見てユーザーの自部署所属を検証する（`CheckDepartmentAccess::handle` の後方互換分岐）。checkDuplicate JS は `department` を送っているため有効。

- [ ] **Step 2: PHP構文検証**

Run: `php -l routes/web.php`
Expected: `No syntax errors detected in routes/web.php`

- [ ] **Step 3: ルートの middleware を確認**

Run: `php artisan route:list --path=api/customers -v 2>&1 | head -40`
Expected: `api/customers/check-duplicate` の行に `role:executive,manager` と `department.access` が表示される

- [ ] **Step 4: コミット**

```bash
git add routes/web.php
git commit -m "fix(security): 買主重複チェックAPIに認可を追加（manager以上＋自部署） [V1]

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: checkDuplicate の検索分離・住所除外・監査ログ（V2）

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php:297-338`

- [ ] **Step 1: checkDuplicate メソッドを置き換え**

現状（297-338）の `checkDuplicate` メソッド全体を以下に置き換える:

```php
    /**
     * 重複チェック（Ajax）
     * 自部署: 姓名一致で二重登録を警告（自部署PIIは閲覧可）。
     * 他部署: 姓名＋都道府県＋市区町村の完全一致時のみ検知し、住所は返さない（PII最小化）。
     */
    public function checkDuplicate(Request $request)
    {
        $lastName    = $request->input('last_name');
        $firstName   = $request->input('first_name');
        $prefecture  = $request->input('prefecture');
        $city        = $request->input('city');
        $currentDept = $request->input('department');
        $excludeId   = $request->input('exclude_id');

        if (!$lastName || !$firstName || !in_array($currentDept, ['housing', 'realestate'], true)) {
            return response()->json(['duplicates' => []]);
        }

        $results = [];

        // (1) 自部署内の同名（二重登録防止）— 姓名一致。自部署のPIIは元々閲覧可
        $sameDeptQuery = Buyer::where('last_name', $lastName)
            ->where('first_name', $firstName)
            ->whereHas('departments', function ($q) use ($currentDept) {
                $q->where('department', $currentDept);
            });
        if ($excludeId) {
            $sameDeptQuery->where('id', '!=', $excludeId);
        }
        foreach ($sameDeptQuery->get() as $buyer) {
            $results[] = [
                'id'         => $buyer->id,
                'full_name'  => $buyer->full_name,
                'address'    => trim(($buyer->prefecture ?? '') . ($buyer->city ?? '')),
                'same_dept'  => true,
                'other_dept' => [],
            ];
        }

        // (2) 他部署の同名（部署共有候補）— 姓名＋都道府県＋市区町村の完全一致を必須。
        //     住所を持つ正規利用者のみヒットさせ、姓名総当たりによる他部署PII列挙を防ぐ。
        //     戻り値に住所は含めない（入力者が既に把握している姓名のみ提示）。
        if ($prefecture && $city) {
            $otherDeptQuery = Buyer::where('last_name', $lastName)
                ->where('first_name', $firstName)
                ->where('prefecture', $prefecture)
                ->where('city', $city)
                ->whereDoesntHave('departments', function ($q) use ($currentDept) {
                    $q->where('department', $currentDept);
                })
                ->whereHas('departments'); // 何らかの部署に実在所属
            if ($excludeId) {
                $otherDeptQuery->where('id', '!=', $excludeId);
            }
            $otherBuyers = $otherDeptQuery->with('departments')->get();

            foreach ($otherBuyers as $buyer) {
                $results[] = [
                    'id'         => $buyer->id,
                    'full_name'  => $buyer->full_name,
                    'same_dept'  => false,
                    'other_dept' => $buyer->departments->pluck('department')->unique()->values()->toArray(),
                ];
            }

            // 監査ログ: 他部署ヒットが発生した場合
            if ($otherBuyers->isNotEmpty()) {
                \Log::info('buyer cross-dept duplicate detected', [
                    'user_id'      => $request->user()?->id,
                    'search_name'  => $lastName . $firstName,
                    'current_dept' => $currentDept,
                    'hit_ids'      => $otherBuyers->pluck('id')->toArray(),
                ]);
            }
        }

        return response()->json(['duplicates' => $results]);
    }
```

`\Log` はフルパス参照のため `use` 追加不要。

- [ ] **Step 2: PHP構文検証**

Run: `php -l app/Http/Controllers/CustomerController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

```bash
git add app/Http/Controllers/CustomerController.php
git commit -m "fix(security): 買主重複チェックを自部署/他部署で分離し他部署の住所露出を排除 [V2]

他部署は姓名＋都道府県＋市区町村の完全一致時のみ検知し戻り値から住所を除外。
他部署ヒットを監査ログに記録。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: addToDepartment の取込検証・監査ログ（V3）

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php:343-362`

- [ ] **Step 1: addToDepartment メソッドを置き換え**

現状（343-362）の `addToDepartment` メソッド全体を以下に置き換える:

```php
    /**
     * 他部署追加（Ajax）
     * 取込対象は「checkDuplicate で姓名＋住所が一致してヒットした他部署買主」のみ。
     * buyer ID を知るだけ・姓名だけでは取込めない（IDOR 対策）。
     */
    public function addToDepartment(Request $request, Buyer $buyer)
    {
        $request->validate([
            'department'    => 'required|in:housing,realestate',
            'acquired_date' => 'required|date',
            'last_name'     => 'required',
            'first_name'    => 'required',
            'prefecture'    => 'required',
            'city'          => 'required',
        ]);

        // 取込対象の正当性検証: 姓名＋住所が完全一致し、かつ他部署に実在所属していること
        abort_unless(
            $buyer->last_name    === $request->input('last_name')
            && $buyer->first_name === $request->input('first_name')
            && $buyer->prefecture === $request->input('prefecture')
            && $buyer->city       === $request->input('city')
            && $buyer->departments()->exists(),
            404
        );

        $dept = $request->input('department');

        if ($buyer->belongsToDepartment($dept)) {
            return response()->json(['error' => 'すでにこの部署に登録されています'], 422);
        }

        $buyer->addToDepartment($dept, $request->input('acquired_date'));

        // 監査ログ: 部署横断取込
        \Log::info('buyer added to department (cross-dept share)', [
            'user_id'  => $request->user()?->id,
            'buyer_id' => $buyer->id,
            'to_dept'  => $dept,
        ]);

        return response()->json([
            'success'  => true,
            'redirect' => route("{$dept}.customers.show", $buyer),
        ]);
    }
```

- [ ] **Step 2: PHP構文検証**

Run: `php -l app/Http/Controllers/CustomerController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

```bash
git add app/Http/Controllers/CustomerController.php
git commit -m "fix(security): 買主の他部署取込に姓名＋住所＋他部署実在の検証を追加 [V3]

任意buyer IDの取込を遮断。取込実行を監査ログに記録。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: UI 変更（他部署PII非表示・発火追加・送信追加）

**Files:**
- Modify: `resources/views/buyers/_form.blade.php:280-284`（他部署表示）
- Modify: `resources/views/buyers/_form.blade.php:186-199`（発火イベント）
- Modify: `resources/views/buyers/_form.blade.php:409-429`（addToDepartment JS）

- [ ] **Step 1: 他部署ヒット表示から住所・他部署リンクを削除**

現状（280-284）:
```blade
                    {{-- 他部署にのみ既存 --}}
                    <div x-show="!dup.same_dept && dup.other_dept.length > 0">
                        <strong x-text="getDeptLabel(dup.other_dept[0])"></strong>に同名の顧客が登録されています：<a x-bind:href="'/' + dup.other_dept[0] + '/customers/' + dup.id" style="color: #1d4ed8; text-decoration: underline;" x-text="dup.full_name + '（' + dup.address + '）'"></a><br>
                        <button type="button" x-on:click="addToDepartment(dup.id)" style="margin-top: 8px; margin-right: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; background: #059669; color: #fff;">この顧客を{{ $deptLabel }}にも追加</button>
                        <button type="button" x-on:click="dismissDuplicate(dup.id)" style="margin-top: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; cursor: pointer; background: #fff; color: #374151; border: 1px solid #9ca3af;">別人として新規登録</button>
                    </div>
```

変更後（住所表示と他部署 show リンクを削除、氏名はプレーンテキスト）:
```blade
                    {{-- 他部署にのみ既存（住所・他部署リンクは出さない — PII最小化） --}}
                    <div x-show="!dup.same_dept && dup.other_dept.length > 0">
                        <strong x-text="getDeptLabel(dup.other_dept[0])"></strong>に同名の顧客（<span x-text="dup.full_name"></span>）が登録されています<br>
                        <button type="button" x-on:click="addToDepartment(dup.id)" style="margin-top: 8px; margin-right: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; background: #059669; color: #fff;">この顧客を{{ $deptLabel }}にも追加</button>
                        <button type="button" x-on:click="dismissDuplicate(dup.id)" style="margin-top: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; cursor: pointer; background: #fff; color: #374151; border: 1px solid #9ca3af;">別人として新規登録</button>
                    </div>
```

- [ ] **Step 2: 都道府県 select / 市区町村 input に発火イベントを追加**

現状（186-187）:
```blade
            <select name="prefecture" x-ref="prefecture"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
```
変更後（`x-on:change="checkDuplicate()"` 追加）:
```blade
            <select name="prefecture" x-ref="prefecture"
                    x-on:change="checkDuplicate()"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
```

現状（196-199）:
```blade
            <input type="text" name="city" x-ref="city"
                   value="{{ old('city', $isEdit ? $buyer->city : '') }}"
                   placeholder="松山市"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
```
変更後（`x-on:blur="checkDuplicate()"` 追加）:
```blade
            <input type="text" name="city" x-ref="city"
                   value="{{ old('city', $isEdit ? $buyer->city : '') }}"
                   placeholder="松山市"
                   x-on:blur="checkDuplicate()"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
```

- [ ] **Step 3: addToDepartment JS に姓名・住所の送信を追加**

現状（409-429）:
```blade
        addToDepartment: function(buyerId) {
            var acquiredDate = document.querySelector('input[name="acquired_date"]').value;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '{{ url("/api/customers") }}/' + buyerId + '/add-department');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var data = JSON.parse(xhr.responseText);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                }
            };
            xhr.send(JSON.stringify({
                department: '{{ $department }}',
                acquired_date: acquiredDate
            }));
        },
```

変更後（`var self = this;` 追加、send に姓名・住所を追加）:
```blade
        addToDepartment: function(buyerId) {
            var self = this;
            var acquiredDate = document.querySelector('input[name="acquired_date"]').value;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '{{ url("/api/customers") }}/' + buyerId + '/add-department');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var data = JSON.parse(xhr.responseText);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                }
            };
            xhr.send(JSON.stringify({
                department: '{{ $department }}',
                acquired_date: acquiredDate,
                last_name: document.querySelector('input[name="last_name"]').value,
                first_name: document.querySelector('input[name="first_name"]').value,
                prefecture: self.$refs.prefecture ? self.$refs.prefecture.value : '',
                city: self.$refs.city ? self.$refs.city.value : ''
            }));
        },
```

- [ ] **Step 4: Blade構文検証（コンパイル）**

Run: `php artisan view:cache 2>&1 | tail -5 && php artisan view:clear`
Expected: `view:cache` がエラーなく完了（`INFO Blade templates cached successfully.`）→ `view:clear` で開発用にクリア

- [ ] **Step 5: コミット**

```bash
git add resources/views/buyers/_form.blade.php
git commit -m "fix(security): 買主フォームの他部署同名表示から住所・リンクを削除し取込時に住所を送信 [V2/V3]

他部署検知の発火を都道府県change・市区町村blurにも追加。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: 静的検証まとめ・本番反映

**Files:** なし（検証・デプロイ手順）

- [ ] **Step 1: 変更ファイルの構文を一括検証**

Run（worktree 内）:
```bash
php -l routes/web.php && php -l app/Http/Controllers/CustomerController.php && php artisan view:cache && php artisan view:clear && php artisan route:list --path=api/customers -v 2>&1 | head -40
```
Expected: 構文エラー無し。route:list に check-duplicate=`role:executive,manager`+`department.access`、add-department=`role:executive,manager`+`department.access` が表示

- [ ] **Step 2: main repo へ FF マージ**

```bash
git -C /Users/masanori/site/manage checkout 13.x
git -C /Users/masanori/site/manage merge --ff-only claude/mystifying-sinoussi-5f16e5
```

- [ ] **Step 3: main repo で dev 依存を入れテスト実行**

```bash
cd /Users/masanori/site/manage && composer install
php artisan test --testsuite=Feature 2>&1 | tail -30
```
注: `buyers` 非依存の既存 Security テスト（添付認可・部署MW）がパスすること。buyers 依存ロジックは Feature テスト不可のため本 Step では検証しない（既存の `DashboardControllerTest` 失敗は本変更と無関係の別負債）。

- [ ] **Step 4: 本番デプロイ前に dev 依存を戻す**

```bash
cd /Users/masanori/site/manage && composer install --no-dev
```

- [ ] **Step 5: デプロイ**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```
Expected: rsync 成功 ＋ 本番で `config:cache && route:cache && view:cache` 成功

- [ ] **Step 6: 本番ブラウザ実機確認（playwright）**

確認項目:
1. staff アカウントで `/api/customers/check-duplicate` が 403（または登録フォーム自体非表示）
2. manager で登録フォーム→姓名入力のみ（住所未入力）では他部署検知が出ない
3. 都道府県＋市区町村まで入力し、他部署に同名＋同住所の買主が居る場合のみ「○○事業に同名の顧客」表示＋取込ボタン（住所文字列・他部署リンクは出ない）
4. 取込ボタンで自部署に追加され show に遷移できる

---

## Self-Review 結果

- **Spec coverage**: V1→Task1 / V2→Task2・Task4 / V3→Task3・Task4 / 監査ログ→Task2・Task3 / UI→Task4 / テスト・本番反映→Task5。全要件カバー
- **Placeholder scan**: 各 Step に実コードを記載。プレースホルダ無し
- **Type consistency**: checkDuplicate 戻り値キー（`id`/`full_name`/`address`/`same_dept`/`other_dept`）と UI 参照（`dup.*`）が一致。他部署は `address` を返さず UI も `dup.address` を参照しない（Task4 Step1 で削除）
