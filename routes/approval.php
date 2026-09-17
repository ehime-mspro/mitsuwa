<?php

use App\Http\Controllers\Approval\HomeController;
use App\Http\Controllers\Approval\OrganizationController;
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
});
