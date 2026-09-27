<?php

use App\Http\Controllers\Approval\HomeController;
use App\Http\Controllers\Approval\OrganizationController;
use App\Http\Controllers\Approval\RelatedNumberController;
use App\Http\Controllers\Approval\TypeController;
use App\Http\Controllers\Approval\UserController;
use App\Http\Controllers\Approval\UserImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 決裁申請
|--------------------------------------------------------------------------
|
| ルート名はすべて `approvals.` で始める。web グループの門番 RestrictApprovalOnlyUsers が
| この接頭辞で「決裁の画面」を判定するので、**別の接頭辞を使わないこと**。
|
| このファイルは routes/web.php の末尾から読み込む（`auth` と `password.change` の
| グループの中で読むので、ここでミドルウェアを重ねて書かない）。
|
*/

Route::get('/approvals', [HomeController::class, 'index'])->name('approvals.home');

/*
|--------------------------------------------------------------------------
| 決裁の管理（決裁の管理者に指定された人だけ）
|--------------------------------------------------------------------------
|
| ⚠ パラメータ名は `{approvalCompany}` / `{approvalDepartment}` / `{mailDomain}`。
|   `{department}` は基幹で別の意味を持つ（`CheckDepartmentAccess` が `$request->route('department')`
|   を読む）ので、同じ名前を使わない。
|
| ⚠ 暗黙のモデル結合は、型宣言の引数名がパラメータ名と一致したときだけ働く。
|   `{approvalCompany}` は `ApprovalCompany $approvalCompany` と書く（工程表で踏んだ罠）。
|
*/
Route::middleware('approval.admin')->prefix('approvals/admin')->name('approvals.admin.')->group(function () {

    // 利用者の管理
    //
    // ⚠ `/users/reissue-bulk` と `/users/import*` を `/users/{user}` より前に置く
    //   （登録順がマッチの優先順）。今は HTTP メソッドが違うので当たらないが、
    //   あとで POST /users/{user} を足した人が気づけない形で壊れる。
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/reissue-bulk', [UserController::class, 'reissueBulk'])->name('users.reissueBulk');

    // 社員の CSV 一括登録（設計書 §5.10）
    Route::get('/users/import', [UserImportController::class, 'form'])->name('users.import');
    Route::get('/users/import/template', [UserImportController::class, 'template'])->name('users.import.template');
    Route::post('/users/import/preview', [UserImportController::class, 'preview'])->name('users.import.preview');
    Route::post('/users/import/execute', [UserImportController::class, 'execute'])->name('users.import.execute');

    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggleStatus');
    Route::post('/users/{user}/reissue', [UserController::class, 'reissue'])->name('users.reissue');

    // 部門の管理
    Route::get('/organization', [OrganizationController::class, 'index'])->name('organization.index');

    Route::post('/organization/companies', [OrganizationController::class, 'storeCompany'])->name('organization.companies.store');
    Route::put('/organization/companies/{approvalCompany}', [OrganizationController::class, 'updateCompany'])->name('organization.companies.update');
    Route::delete('/organization/companies/{approvalCompany}', [OrganizationController::class, 'destroyCompany'])->name('organization.companies.destroy');

    Route::post('/organization/departments', [OrganizationController::class, 'storeDepartment'])->name('organization.departments.store');
    Route::put('/organization/departments/{approvalDepartment}', [OrganizationController::class, 'updateDepartment'])->name('organization.departments.update');
    Route::delete('/organization/departments/{approvalDepartment}', [OrganizationController::class, 'destroyDepartment'])->name('organization.departments.destroy');

    Route::post('/organization/mail-domains', [OrganizationController::class, 'storeMailDomain'])->name('organization.mailDomains.store');
    Route::delete('/organization/mail-domains/{mailDomain}', [OrganizationController::class, 'destroyMailDomain'])->name('organization.mailDomains.destroy');

    // 申請種類の管理（段階2 設計書 §5.5）。使い始める前から使える（準備の画面。D1）
    // ⚠ パラメータ名は `{approvalType}`（`{type}` は基幹で別の意味に使われうるので避ける）
    Route::get('/types', [TypeController::class, 'index'])->name('types.index');
    Route::post('/types', [TypeController::class, 'store'])->name('types.store');
    Route::put('/types/{approvalType}', [TypeController::class, 'update'])->name('types.update');
    Route::delete('/types/{approvalType}', [TypeController::class, 'destroy'])->name('types.destroy');
});

/*
|--------------------------------------------------------------------------
| 申請を回す画面（段階2。使い始めるまで誰にも見せない）
|--------------------------------------------------------------------------
|
| ⚠ 門番 `approval.launched` は、使い始める前（approval_settings.launched_at が空）は
|   画面を開く GET をホームへ送り、それ以外を 404 にする（段階2 設計書 §5.2・D1）。
|   このグループの外に申請の画面を足さないこと（LaunchGateTest が全件分類で止める）。
| ⚠ パラメータ名は `{approvalRequest}` / `{approvalAttachment}`（モデル名の camelCase）。
| ⚠ `/requests/create` を `/requests/{approvalRequest}` より前に置く（登録順がマッチの優先順）。
|
*/
Route::middleware('approval.launched')->prefix('approvals')->name('approvals.')->group(function () {

    // 関連する決裁No の候補（Ajax・JSON。設計書 §5.6）
    Route::get('/numbers', [RelatedNumberController::class, 'search'])->name('numbers.search');
});
