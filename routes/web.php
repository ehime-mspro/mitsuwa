<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 認証ルート（5ルート）
|--------------------------------------------------------------------------
*/

// ゲスト（未認証）ユーザーのみアクセス可能
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // ブルートフォース対策: ログイン試行を 1 分あたり 5 回に制限
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
});

// 認証済みユーザー
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| 認証必須ルート（パスワード変更強制ミドルウェア適用）
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'password.change'])->group(function () {

    /*
    |----------------------------------------------------------------------
    | パスワード変更（2ルート）
    |----------------------------------------------------------------------
    */
    Route::get('/password/change', [PasswordController::class, 'showChange'])->name('password.change');
    Route::put('/password/change', [PasswordController::class, 'change'])->name('password.update');

    /*
    |----------------------------------------------------------------------
    | ダッシュボード（2ルート）
    |----------------------------------------------------------------------
    */

    // ダッシュボードルートの振り分け（ロールに応じてリダイレクト）
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::get('/dashboard', function () {
        if (auth()->user()->role->isExecutive()) {
            return redirect()->route('dashboard.executive');
        }
        return redirect()->route('dashboard.tenant');
    })->name('dashboard');

    // 経営ダッシュボード（経営層のみ）
    Route::get('/dashboard/executive', [DashboardController::class, 'executive'])
        ->middleware('role:executive')
        ->name('dashboard.executive');

    // テナントダッシュボード（全ロール）
    Route::get('/dashboard/tenant', [DashboardController::class, 'tenant'])
        ->name('dashboard.tenant');

    /*
    |----------------------------------------------------------------------
    | システム管理（10ルート）※経営層のみ
    |----------------------------------------------------------------------
    */
    Route::middleware('role:executive')->prefix('admin')->group(function () {
        // ユーザー管理
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])
            ->name('admin.users.index');
        Route::post('/users', [\App\Http\Controllers\Admin\UserController::class, 'store'])
            ->name('admin.users.store');
        Route::put('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])
            ->name('admin.users.update');
        Route::put('/users/{user}/reset-password', [\App\Http\Controllers\Admin\UserController::class, 'resetPassword'])
            ->name('admin.users.resetPassword');
        Route::patch('/users/{user}/toggle-status', [\App\Http\Controllers\Admin\UserController::class, 'toggleStatus'])
            ->name('admin.users.toggleStatus');

        // 希望用途マスター
        Route::post('/master/usage-types/reorder', [\App\Http\Controllers\Admin\UsageTypeController::class, 'reorder'])
            ->name('admin.master.usage-types.reorder');
        Route::get('/master/usage-types', [\App\Http\Controllers\Admin\UsageTypeController::class, 'index'])
            ->name('admin.master.usage-types.index');
        Route::post('/master/usage-types', [\App\Http\Controllers\Admin\UsageTypeController::class, 'store'])
            ->name('admin.master.usage-types.store');
        Route::put('/master/usage-types/{usageType}', [\App\Http\Controllers\Admin\UsageTypeController::class, 'update'])
            ->name('admin.master.usage-types.update');
        Route::delete('/master/usage-types/{usageType}', [\App\Http\Controllers\Admin\UsageTypeController::class, 'destroy'])
            ->name('admin.master.usage-types.destroy');

        // 構造マスター
        Route::post('/master/structure-types/reorder', [\App\Http\Controllers\Admin\StructureTypeController::class, 'reorder'])
            ->name('admin.master.structure-types.reorder');
        Route::get('/master/structure-types', [\App\Http\Controllers\Admin\StructureTypeController::class, 'index'])
            ->name('admin.master.structure-types.index');
        Route::post('/master/structure-types', [\App\Http\Controllers\Admin\StructureTypeController::class, 'store'])
            ->name('admin.master.structure-types.store');
        Route::put('/master/structure-types/{structureType}', [\App\Http\Controllers\Admin\StructureTypeController::class, 'update'])
            ->name('admin.master.structure-types.update');
        Route::delete('/master/structure-types/{structureType}', [\App\Http\Controllers\Admin\StructureTypeController::class, 'destroy'])
            ->name('admin.master.structure-types.destroy');

        // 用途地域マスター
        Route::post('/master/zoning-types/reorder', [\App\Http\Controllers\Admin\ZoningTypeController::class, 'reorder'])
            ->name('admin.master.zoning-types.reorder');
        Route::get('/master/zoning-types', [\App\Http\Controllers\Admin\ZoningTypeController::class, 'index'])
            ->name('admin.master.zoning-types.index');
        Route::post('/master/zoning-types', [\App\Http\Controllers\Admin\ZoningTypeController::class, 'store'])
            ->name('admin.master.zoning-types.store');
        Route::put('/master/zoning-types/{zoningType}', [\App\Http\Controllers\Admin\ZoningTypeController::class, 'update'])
            ->name('admin.master.zoning-types.update');
        Route::delete('/master/zoning-types/{zoningType}', [\App\Http\Controllers\Admin\ZoningTypeController::class, 'destroy'])
            ->name('admin.master.zoning-types.destroy');

        // DAD 専門分野マスター
        Route::post('/master/dad-specialties/reorder', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'reorder'])
            ->name('admin.master.dad-specialties.reorder');
        Route::get('/master/dad-specialties', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'index'])
            ->name('admin.master.dad-specialties.index');
        Route::get('/master/dad-specialties/create', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'create'])
            ->name('admin.master.dad-specialties.create');
        Route::post('/master/dad-specialties', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'store'])
            ->name('admin.master.dad-specialties.store');
        Route::get('/master/dad-specialties/{dadSpecialty}/edit', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'edit'])
            ->name('admin.master.dad-specialties.edit');
        Route::put('/master/dad-specialties/{dadSpecialty}', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'update'])
            ->name('admin.master.dad-specialties.update');
        Route::delete('/master/dad-specialties/{dadSpecialty}', [\App\Http\Controllers\Admin\DadSpecialtyController::class, 'destroy'])
            ->name('admin.master.dad-specialties.destroy');

        // ZEAL 試算表 項目マスター
        Route::post('/master/zeal-simulation-categories/reorder', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'reorder'])
            ->name('admin.master.zeal-simulation-categories.reorder');
        Route::get('/master/zeal-simulation-categories', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'index'])
            ->name('admin.master.zeal-simulation-categories.index');
        Route::get('/master/zeal-simulation-categories/create', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'create'])
            ->name('admin.master.zeal-simulation-categories.create');
        Route::post('/master/zeal-simulation-categories', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'store'])
            ->name('admin.master.zeal-simulation-categories.store');
        Route::get('/master/zeal-simulation-categories/{zealSimulationCategory}/edit', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'edit'])
            ->name('admin.master.zeal-simulation-categories.edit');
        Route::put('/master/zeal-simulation-categories/{zealSimulationCategory}', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'update'])
            ->name('admin.master.zeal-simulation-categories.update');
        Route::delete('/master/zeal-simulation-categories/{zealSimulationCategory}', [\App\Http\Controllers\Admin\ZealSimulationCategoryController::class, 'destroy'])
            ->name('admin.master.zeal-simulation-categories.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | テナント物件管理（7ルート）— STEP 4
    |----------------------------------------------------------------------
    */
    Route::prefix('tenant')->middleware('department.access:tenant')->group(function () {
        // 物件一覧・登録（全ロール閲覧可、登録は経営層+管理者）
        Route::get('/properties', [\App\Http\Controllers\Tenant\PropertyController::class, 'index'])
            ->name('tenant.properties.index');

        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/create', [\App\Http\Controllers\Tenant\PropertyController::class, 'create'])
                ->name('tenant.properties.create');
            Route::post('/properties', [\App\Http\Controllers\Tenant\PropertyController::class, 'store'])
                ->name('tenant.properties.store');
        });

        // 物件詳細（全ロール閲覧可）
        Route::get('/properties/{property}', [\App\Http\Controllers\Tenant\PropertyController::class, 'show'])
            ->name('tenant.properties.show');

        // 物件編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/edit', [\App\Http\Controllers\Tenant\PropertyController::class, 'edit'])
                ->name('tenant.properties.edit');
            Route::put('/properties/{property}', [\App\Http\Controllers\Tenant\PropertyController::class, 'update'])
                ->name('tenant.properties.update');
        });

        // 物件削除（経営層のみ）
        Route::delete('/properties/{property}', [\App\Http\Controllers\Tenant\PropertyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.properties.destroy');

        /*
        |------------------------------------------------------------------
        | テナント区画管理（7ルート）— STEP 5
        |------------------------------------------------------------------
        */

        // 部屋一覧（全ロール閲覧可）
        Route::get('/units', [\App\Http\Controllers\Tenant\UnitController::class, 'index'])
            ->name('tenant.units.index');

        // 区画登録（経営層+管理者）— 物件配下
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/units/create', [\App\Http\Controllers\Tenant\UnitController::class, 'create'])
                ->name('tenant.units.create');
            Route::post('/properties/{property}/units', [\App\Http\Controllers\Tenant\UnitController::class, 'store'])
                ->name('tenant.units.store');
        });

        // 区画詳細（全ロール閲覧可）
        Route::get('/units/{unit}', [\App\Http\Controllers\Tenant\UnitController::class, 'show'])
            ->name('tenant.units.show');

        // 区画編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/units/{unit}/edit', [\App\Http\Controllers\Tenant\UnitController::class, 'edit'])
                ->name('tenant.units.edit');
            Route::put('/units/{unit}', [\App\Http\Controllers\Tenant\UnitController::class, 'update'])
                ->name('tenant.units.update');
        });

        // 区画削除（経営層のみ）
        Route::delete('/units/{unit}', [\App\Http\Controllers\Tenant\UnitController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.units.destroy');

        // 区画ステータス変更（経営層+管理者）— vacant↔negotiating
        Route::patch('/units/{unit}/status', [\App\Http\Controllers\Tenant\UnitController::class, 'updateStatus'])
            ->middleware('role:executive,manager')
            ->name('tenant.units.updateStatus');

        // 募集家賃の賃料改定（経営層のみ）— 空室・商談中の区画
        Route::middleware('role:executive')->group(function () {
            Route::get('/units/{unit}/revise', [\App\Http\Controllers\Tenant\UnitController::class, 'showReviseRent'])
                ->name('tenant.units.revise');
            Route::post('/units/{unit}/revise', [\App\Http\Controllers\Tenant\UnitController::class, 'reviseRent'])
                ->name('tenant.units.revise.execute');
        });

        /*
        |------------------------------------------------------------------
        | テナント契約管理（10ルート）— STEP 6
        |------------------------------------------------------------------
        */

        // 契約一覧（全ロール閲覧可）
        Route::get('/contracts', [\App\Http\Controllers\Tenant\ContractController::class, 'index'])
            ->name('tenant.contracts.index');

        // 契約登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/create', [\App\Http\Controllers\Tenant\ContractController::class, 'create'])
                ->name('tenant.contracts.create');
            Route::post('/contracts', [\App\Http\Controllers\Tenant\ContractController::class, 'store'])
                ->name('tenant.contracts.store');
        });

        // 契約詳細（全ロール閲覧可）
        Route::get('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'show'])
            ->name('tenant.contracts.show');

        // 契約編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/{contract}/edit', [\App\Http\Controllers\Tenant\ContractController::class, 'edit'])
                ->name('tenant.contracts.edit');
            Route::put('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'update'])
                ->name('tenant.contracts.update');
        });

        // 解約処理（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/{contract}/terminate', [\App\Http\Controllers\Tenant\ContractController::class, 'showTerminate'])
                ->name('tenant.contracts.terminate');
            Route::put('/contracts/{contract}/terminate', [\App\Http\Controllers\Tenant\ContractController::class, 'terminate'])
                ->name('tenant.contracts.terminate.execute');
        });

        // 賃料改定（経営層のみ）
        Route::middleware('role:executive')->group(function () {
            Route::get('/contracts/{contract}/revise', [\App\Http\Controllers\Tenant\ContractController::class, 'showRevise'])
                ->name('tenant.contracts.revise');
            Route::post('/contracts/{contract}/revise', [\App\Http\Controllers\Tenant\ContractController::class, 'revise'])
                ->name('tenant.contracts.revise.execute');
        });
        /*
        |------------------------------------------------------------------
        | テナント投資案件管理（7ルート）— STEP 8
        |------------------------------------------------------------------
        */

        // 投資案件一覧（全ロール閲覧可）
        Route::get('/investments', [\App\Http\Controllers\Tenant\InvestmentController::class, 'index'])
            ->name('tenant.investments.index');

        // 投資案件登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/investments/create', [\App\Http\Controllers\Tenant\InvestmentController::class, 'create'])
                ->name('tenant.investments.create');
            Route::post('/investments', [\App\Http\Controllers\Tenant\InvestmentController::class, 'store'])
                ->name('tenant.investments.store');
        });

        // 投資案件詳細（全ロール閲覧可）
        Route::get('/investments/{investment}', [\App\Http\Controllers\Tenant\InvestmentController::class, 'show'])
            ->name('tenant.investments.show');

        // 投資案件編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/investments/{investment}/edit', [\App\Http\Controllers\Tenant\InvestmentController::class, 'edit'])
                ->name('tenant.investments.edit');
            Route::put('/investments/{investment}', [\App\Http\Controllers\Tenant\InvestmentController::class, 'update'])
                ->name('tenant.investments.update');
        });

        // 投資案件削除（経営層のみ）
        Route::delete('/investments/{investment}', [\App\Http\Controllers\Tenant\InvestmentController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.investments.destroy');

        /*
        |------------------------------------------------------------------
        | テナント一般修繕管理（7ルート）— STEP 8
        |------------------------------------------------------------------
        */

        // 修繕一覧（全ロール閲覧可）
        Route::get('/repairs', [\App\Http\Controllers\Tenant\RepairController::class, 'index'])
            ->name('tenant.repairs.index');

        // 修繕登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/repairs/create', [\App\Http\Controllers\Tenant\RepairController::class, 'create'])
                ->name('tenant.repairs.create');
            Route::post('/repairs', [\App\Http\Controllers\Tenant\RepairController::class, 'store'])
                ->name('tenant.repairs.store');
        });

        // 修繕詳細（全ロール閲覧可）
        Route::get('/repairs/{repair}', [\App\Http\Controllers\Tenant\RepairController::class, 'show'])
            ->name('tenant.repairs.show');

        // 修繕編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/repairs/{repair}/edit', [\App\Http\Controllers\Tenant\RepairController::class, 'edit'])
                ->name('tenant.repairs.edit');
            Route::put('/repairs/{repair}', [\App\Http\Controllers\Tenant\RepairController::class, 'update'])
                ->name('tenant.repairs.update');
        });

        // 修繕削除（経営層のみ）
        Route::delete('/repairs/{repair}', [\App\Http\Controllers\Tenant\RepairController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.repairs.destroy');

        /*
        |------------------------------------------------------------------
        | テナント顧客管理（7ルート）— STEP 10
        |------------------------------------------------------------------
        */

        // 顧客一覧（全ロール閲覧可）
        Route::get('/customers', [\App\Http\Controllers\Tenant\CustomerController::class, 'index'])
            ->name('tenant.customers.index');

        // 顧客登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/create', [\App\Http\Controllers\Tenant\CustomerController::class, 'create'])
                ->name('tenant.customers.create');
            Route::post('/customers', [\App\Http\Controllers\Tenant\CustomerController::class, 'store'])
                ->name('tenant.customers.store');
        });

        // 顧客詳細（全ロール閲覧可）
        Route::get('/customers/{customer}', [\App\Http\Controllers\Tenant\CustomerController::class, 'show'])
            ->name('tenant.customers.show');

        // 顧客編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/{customer}/edit', [\App\Http\Controllers\Tenant\CustomerController::class, 'edit'])
                ->name('tenant.customers.edit');
            Route::put('/customers/{customer}', [\App\Http\Controllers\Tenant\CustomerController::class, 'update'])
                ->name('tenant.customers.update');
        });

        // 顧客削除（経営層のみ）
        Route::delete('/customers/{customer}', [\App\Http\Controllers\Tenant\CustomerController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.customers.destroy');

        /*
        |------------------------------------------------------------------
        | テナント問合せ管理（9ルート）— STEP 9
        |------------------------------------------------------------------
        */

        // 問合せ一覧（全ロール閲覧可）
        Route::get('/inquiries', [\App\Http\Controllers\Tenant\InquiryController::class, 'index'])
            ->name('tenant.inquiries.index');

        // 問合せ登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/inquiries/create', [\App\Http\Controllers\Tenant\InquiryController::class, 'create'])
                ->name('tenant.inquiries.create');
            Route::post('/inquiries', [\App\Http\Controllers\Tenant\InquiryController::class, 'store'])
                ->name('tenant.inquiries.store');
        });

        // 問合せ詳細（全ロール閲覧可）
        Route::get('/inquiries/{inquiry}', [\App\Http\Controllers\Tenant\InquiryController::class, 'show'])
            ->name('tenant.inquiries.show');

        // 問合せ編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/inquiries/{inquiry}/edit', [\App\Http\Controllers\Tenant\InquiryController::class, 'edit'])
                ->name('tenant.inquiries.edit');
            Route::put('/inquiries/{inquiry}', [\App\Http\Controllers\Tenant\InquiryController::class, 'update'])
                ->name('tenant.inquiries.update');
        });

        // 問合せ削除（経営層のみ）
        Route::delete('/inquiries/{inquiry}', [\App\Http\Controllers\Tenant\InquiryController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('tenant.inquiries.destroy');

        // 対応履歴追加（経営層+管理者）
        Route::post('/inquiries/{inquiry}/histories', [\App\Http\Controllers\Tenant\InquiryController::class, 'storeHistory'])
            ->middleware('role:executive,manager')
            ->name('tenant.inquiries.storeHistory');

        // ステータス変更（経営層+管理者）
        Route::patch('/inquiries/{inquiry}/status', [\App\Http\Controllers\Tenant\InquiryController::class, 'updateStatus'])
            ->middleware('role:executive,manager')
            ->name('tenant.inquiries.updateStatus');
    });

    /*
    |----------------------------------------------------------------------
    | テナント Ajax API（4ルート）— STEP 6 + STEP 8 + STEP 9補完 + STEP 10
    |----------------------------------------------------------------------
    */

    // 空室・商談中の区画取得（物件選択→区画連動用）
    Route::get('/api/tenant/properties/{property}/vacant-units', [\App\Http\Controllers\Tenant\ContractController::class, 'vacantUnits'])
        ->middleware('department.access:tenant')->name('api.tenant.vacant-units');

    // 物件のフォロー・保留中の問合せ取得（契約登録→関連問合せ連動用）
    Route::get('/api/tenant/properties/{property}/active-inquiries', [\App\Http\Controllers\Tenant\ContractController::class, 'activeInquiries'])
        ->middleware('department.access:tenant')->name('api.tenant.active-inquiries');

    // 顧客検索（契約登録・問合せ登録→顧客Ajax検索用）
    Route::get('/api/tenant/customers/search', [\App\Http\Controllers\Tenant\CustomerController::class, 'search'])
        ->middleware('department.access:tenant')->name('api.tenant.customers.search');
        
    // 住所→郵便番号 逆引きAjax
    Route::get('/api/reverse-zip', [\App\Http\Controllers\CustomerController::class, 'reverseZipLookup'])
        ->name('api.reverse-zip');

    /*
    |----------------------------------------------------------------------
    | ファイル添付（2ルート）— STEP 11
    |----------------------------------------------------------------------
    */

    // ファイルアップロード（Ajax — 経営層+管理者）
    Route::post('/attachments/{type}/{id}', [\App\Http\Controllers\AttachmentController::class, 'store'])
        ->middleware('role:executive,manager')
        ->name('attachments.store')
        ->where('type', 'contracts|investments|repairs|procurements|projects|ms_tenants|dad_projects');

    // ファイル削除（Ajax — 経営層 or アップロード本人 ※Controller内で権限チェック）
    Route::delete('/attachments/{attachment}', [\App\Http\Controllers\AttachmentController::class, 'destroy'])
        ->name('attachments.destroy');

    // ファイル表示・ダウンロード（symlink に依存せず Laravel 経由でストリーミング配信）
    Route::get('/attachments/{attachment}', [\App\Http\Controllers\AttachmentController::class, 'show'])
        ->name('attachments.show');

    /*
    |----------------------------------------------------------------------
    | 賃貸マンション管理（Phase B〜H で段階実装）
    |----------------------------------------------------------------------
    */
    Route::prefix('mansion')->middleware('department.access:mansion')->group(function () {
        // ダッシュボード（Phase H で実装予定のため暫定コメントアウト）
        Route::get('/dashboard', [\App\Http\Controllers\Mansion\DashboardController::class, 'index'])
            ->name('mansion.dashboard');

        // 物件一覧（全ロール閲覧可）
        Route::get('/properties', [\App\Http\Controllers\Mansion\PropertyController::class, 'index'])
            ->name('mansion.properties.index');

        // 物件登録（経営層+管理者）— create/store は show より先に定義
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/create', [\App\Http\Controllers\Mansion\PropertyController::class, 'create'])
                ->name('mansion.properties.create');
            Route::post('/properties', [\App\Http\Controllers\Mansion\PropertyController::class, 'store'])
                ->name('mansion.properties.store');
        });

        // 物件詳細（全ロール閲覧可）
        Route::get('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'show'])
            ->name('mansion.properties.show');

        // 物件編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/edit', [\App\Http\Controllers\Mansion\PropertyController::class, 'edit'])
                ->name('mansion.properties.edit');
            Route::put('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'update'])
                ->name('mansion.properties.update');
        });

        // 物件削除（経営層のみ）
        Route::delete('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('mansion.properties.destroy');

        /*
        |------------------------------------------------------------------
        | 部屋管理（Phase C）
        |------------------------------------------------------------------
        */
        // 部屋登録・編集・更新・削除（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/rooms/create', [\App\Http\Controllers\Mansion\RoomController::class, 'create'])
                ->name('mansion.rooms.create');
            Route::post('/properties/{property}/rooms', [\App\Http\Controllers\Mansion\RoomController::class, 'store'])
                ->name('mansion.rooms.store');
            Route::get('/rooms/{room}/edit', [\App\Http\Controllers\Mansion\RoomController::class, 'edit'])
                ->name('mansion.rooms.edit');
            Route::put('/rooms/{room}', [\App\Http\Controllers\Mansion\RoomController::class, 'update'])
                ->name('mansion.rooms.update');
            Route::delete('/rooms/{room}', [\App\Http\Controllers\Mansion\RoomController::class, 'destroy'])
                ->middleware('role:executive')
                ->name('mansion.rooms.destroy');
        });

        // 部屋ステータス更新（Ajax）— 他モジュールの updateStatus と同様に manager 以上に限定
        Route::patch('/rooms/{room}/status', [\App\Http\Controllers\Mansion\RoomController::class, 'updateStatus'])
            ->middleware('role:executive,manager')
            ->name('mansion.rooms.updateStatus');

        /*
        |------------------------------------------------------------------
        | 駐車場管理（Phase D）
        |------------------------------------------------------------------
        */
        // 駐車場登録・編集・更新・削除（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/parkings/create', [\App\Http\Controllers\Mansion\ParkingController::class, 'create'])
                ->name('mansion.parkings.create');
            Route::post('/properties/{property}/parkings', [\App\Http\Controllers\Mansion\ParkingController::class, 'store'])
                ->name('mansion.parkings.store');
            Route::get('/parkings/{parking}/edit', [\App\Http\Controllers\Mansion\ParkingController::class, 'edit'])
                ->name('mansion.parkings.edit');
            Route::put('/parkings/{parking}', [\App\Http\Controllers\Mansion\ParkingController::class, 'update'])
                ->name('mansion.parkings.update');
            Route::delete('/parkings/{parking}', [\App\Http\Controllers\Mansion\ParkingController::class, 'destroy'])
                ->middleware('role:executive')
                ->name('mansion.parkings.destroy');
        });

        /*
        |------------------------------------------------------------------
        | 入居者管理（Phase E）
        |------------------------------------------------------------------
        | 入居申込書アップロードは既存 AttachmentController（Ajax）を再利用。
        | 申込書表示画面（showApplication）のみ本コントローラーで扱う。
        */
        // 入居者一覧（全ロール閲覧可）
        Route::get('/tenants', [\App\Http\Controllers\Mansion\TenantController::class, 'index'])
            ->name('mansion.tenants.index');

        // 入居者登録（経営層+管理者）— show より先に定義して URL 競合回避
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/tenants/create', [\App\Http\Controllers\Mansion\TenantController::class, 'create'])
                ->name('mansion.tenants.create');
            Route::post('/tenants', [\App\Http\Controllers\Mansion\TenantController::class, 'store'])
                ->name('mansion.tenants.store');
        });

        // 入居者詳細（全ロール閲覧可）
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'show'])
            ->name('mansion.tenants.show');

        // 入居者編集・更新・削除・申込書画面（経営層+管理者、削除のみ経営層）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/tenants/{tenant}/edit', [\App\Http\Controllers\Mansion\TenantController::class, 'edit'])
                ->name('mansion.tenants.edit');
            Route::put('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'update'])
                ->name('mansion.tenants.update');
            Route::delete('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'destroy'])
                ->middleware('role:executive')
                ->name('mansion.tenants.destroy');
            // 入居申込書アップロード画面（アップロード・削除 Ajax は /attachments/ms_tenants/... を使用）
            Route::get('/tenants/{tenant}/application', [\App\Http\Controllers\Mansion\TenantController::class, 'showApplication'])
                ->name('mansion.tenants.application');
        });

        /*
        |------------------------------------------------------------------
        | 部屋契約管理（Phase F）
        |------------------------------------------------------------------
        */
        // 部屋契約一覧（全ロール閲覧可）
        Route::get('/contracts', [\App\Http\Controllers\Mansion\ContractController::class, 'index'])
            ->name('mansion.contracts.index');

        // 部屋契約登録（経営層+管理者）— show より先に定義して URL 競合回避
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/create', [\App\Http\Controllers\Mansion\ContractController::class, 'create'])
                ->name('mansion.contracts.create');
            Route::post('/contracts', [\App\Http\Controllers\Mansion\ContractController::class, 'store'])
                ->name('mansion.contracts.store');
        });

        // 部屋契約詳細（全ロール閲覧可）
        Route::get('/contracts/{contract}', [\App\Http\Controllers\Mansion\ContractController::class, 'show'])
            ->name('mansion.contracts.show');

        // 部屋契約編集・更新・賃料改定・解約（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/{contract}/edit', [\App\Http\Controllers\Mansion\ContractController::class, 'edit'])
                ->name('mansion.contracts.edit');
            Route::put('/contracts/{contract}', [\App\Http\Controllers\Mansion\ContractController::class, 'update'])
                ->name('mansion.contracts.update');
            // 賃料改定
            Route::get('/contracts/{contract}/revise', [\App\Http\Controllers\Mansion\ContractController::class, 'showRevise'])
                ->name('mansion.contracts.revise.show');
            Route::post('/contracts/{contract}/revise', [\App\Http\Controllers\Mansion\ContractController::class, 'revise'])
                ->name('mansion.contracts.revise');
            // 解約
            Route::get('/contracts/{contract}/terminate', [\App\Http\Controllers\Mansion\ContractController::class, 'showTerminate'])
                ->name('mansion.contracts.terminate.show');
            Route::put('/contracts/{contract}/terminate', [\App\Http\Controllers\Mansion\ContractController::class, 'terminate'])
                ->name('mansion.contracts.terminate');
        });

        // === 賃貸マンション: 駐車場契約（単独契約 + 料金改定 + 解約） ===
        // 一覧は全ロール閲覧可、CRUD/改定/解約は manager 以上
        Route::get('/parking-contracts', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'index'])
            ->name('mansion.parking-contracts.index');

        // create / store は show/edit 系より前に配置（URL衝突回避）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/parking-contracts/create', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'create'])
                ->name('mansion.parking-contracts.create');
            Route::post('/parking-contracts', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'store'])
                ->name('mansion.parking-contracts.store');
        });

        // 駐車場契約詳細（全ロール閲覧可）
        Route::get('/parking-contracts/{parkingContract}', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'show'])
            ->name('mansion.parking-contracts.show');

        // 編集・料金改定・解約は manager 以上
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/parking-contracts/{parkingContract}/edit', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'edit'])
                ->name('mansion.parking-contracts.edit');
            Route::put('/parking-contracts/{parkingContract}', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'update'])
                ->name('mansion.parking-contracts.update');
            // 料金改定
            Route::get('/parking-contracts/{parkingContract}/revise', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'showRevise'])
                ->name('mansion.parking-contracts.revise.show');
            Route::post('/parking-contracts/{parkingContract}/revise', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'revise'])
                ->name('mansion.parking-contracts.revise');
            // 解約
            Route::get('/parking-contracts/{parkingContract}/terminate', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'showTerminate'])
                ->name('mansion.parking-contracts.terminate.show');
            Route::put('/parking-contracts/{parkingContract}/terminate', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'terminate'])
                ->name('mansion.parking-contracts.terminate');
        });
    });

    /*
    |----------------------------------------------------------------------
    | 賃貸マンション Ajax API（2ルート）— Phase F
    |----------------------------------------------------------------------
    */
    // 空室取得（物件選択→部屋連動用）
    Route::get('/api/mansion/properties/{property}/vacant-rooms', [\App\Http\Controllers\Mansion\ContractController::class, 'vacantRooms'])
        ->middleware('department.access:mansion')->name('api.mansion.vacant-rooms');

    // 空き駐車場取得（物件選択→駐車場連動用）
    Route::get('/api/mansion/properties/{property}/vacant-parkings', [\App\Http\Controllers\Mansion\ContractController::class, 'vacantParkings'])
        ->middleware('department.access:mansion')->name('api.mansion.vacant-parkings');

    /*
    |----------------------------------------------------------------------
    | 不動産 仕入れ案件管理（7ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('realestate')->middleware('department.access:realestate')->group(function () {
        // 仕入れ案件一覧（全ロール閲覧可）
        Route::get('/procurements', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'index'])
            ->name('realestate.procurements.index');

        // 仕入れ案件登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/procurements/create', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'create'])
                ->name('realestate.procurements.create');
            Route::post('/procurements', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'store'])
                ->name('realestate.procurements.store');
        });

        // 仕入れ案件詳細（全ロール閲覧可）
        Route::get('/procurements/{procurement}', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'show'])
            ->name('realestate.procurements.show');

        // 仕入れ案件編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/procurements/{procurement}/edit', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'edit'])
                ->name('realestate.procurements.edit');
            Route::put('/procurements/{procurement}', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'update'])
                ->name('realestate.procurements.update');
            // 一覧バッジクリックからのステータスのみ Ajax 更新
            Route::patch('/procurements/{procurement}/status', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'updateStatus'])
                ->name('realestate.procurements.update-status');
        });

        // 仕入れ案件削除（経営層のみ）
        Route::delete('/procurements/{procurement}', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.procurements.destroy');
        
        /*
        |------------------------------------------------------------------
        | 不動産 契約管理（9ルート）
        |------------------------------------------------------------------
        */

        // 契約一覧（全ロール閲覧可）
        Route::get('/contracts', [\App\Http\Controllers\RealEstate\ReContractController::class, 'index'])
            ->name('realestate.contracts.index');

        // 契約登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/create', [\App\Http\Controllers\RealEstate\ReContractController::class, 'create'])
                ->name('realestate.contracts.create');
            Route::post('/contracts', [\App\Http\Controllers\RealEstate\ReContractController::class, 'store'])
                ->name('realestate.contracts.store');
        });

        // 契約詳細（全ロール閲覧可）
        Route::get('/contracts/{contract}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'show'])
            ->name('realestate.contracts.show');

        // 契約編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/{contract}/edit', [\App\Http\Controllers\RealEstate\ReContractController::class, 'edit'])
                ->name('realestate.contracts.edit');
            Route::put('/contracts/{contract}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'update'])
                ->name('realestate.contracts.update');
        });

        // 契約削除（経営層のみ）
        Route::delete('/contracts/{contract}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.contracts.destroy');

        // 仲介ステータス変更（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::patch('/contracts/{contract}/close', [\App\Http\Controllers\RealEstate\ReContractController::class, 'close'])
                ->name('realestate.contracts.close');
            Route::patch('/contracts/{contract}/lost', [\App\Http\Controllers\RealEstate\ReContractController::class, 'lost'])
                ->name('realestate.contracts.lost');
        });

        /*
        |------------------------------------------------------------------
        | 仕入れ原価管理 Ajax（3ルート）
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/procurements/{procurement}/costs', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'storeCost'])
                ->name('realestate.procurements.costs.store');
            Route::put('/procurements/{procurement}/costs/{cost}', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'updateCost'])
                ->name('realestate.procurements.costs.update');
            // 試算表 Excel/CSV 取込（バルク投入）
            Route::post('/procurements/{procurement}/costs/bulk-import', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'bulkImportCosts'])
                ->name('realestate.procurements.costs.bulk-import');
        });
        Route::delete('/procurements/{procurement}/costs/{cost}', [\App\Http\Controllers\RealEstate\ProcurementController::class, 'destroyCost'])
            ->middleware('role:executive')
            ->name('realestate.procurements.costs.destroy');

        /*
        |------------------------------------------------------------------
        | 仕入れ先管理（7ルート）
        |------------------------------------------------------------------
        */
        // 仕入れ先一覧（全ロール閲覧可）
        Route::get('/suppliers', [\App\Http\Controllers\RealEstate\SupplierController::class, 'index'])
            ->name('realestate.suppliers.index');

        // 仕入れ先登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/suppliers/create', [\App\Http\Controllers\RealEstate\SupplierController::class, 'create'])
                ->name('realestate.suppliers.create');
            Route::post('/suppliers', [\App\Http\Controllers\RealEstate\SupplierController::class, 'store'])
                ->name('realestate.suppliers.store');
        });

        // 仕入れ先詳細（全ロール閲覧可）
        Route::get('/suppliers/{supplier}', [\App\Http\Controllers\RealEstate\SupplierController::class, 'show'])
            ->name('realestate.suppliers.show');

        // 仕入れ先編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/suppliers/{supplier}/edit', [\App\Http\Controllers\RealEstate\SupplierController::class, 'edit'])
                ->name('realestate.suppliers.edit');
            Route::put('/suppliers/{supplier}', [\App\Http\Controllers\RealEstate\SupplierController::class, 'update'])
                ->name('realestate.suppliers.update');
        });

        // 仕入れ先削除（経営層のみ）
        Route::delete('/suppliers/{supplier}', [\App\Http\Controllers\RealEstate\SupplierController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.suppliers.destroy');
    });
    
    /*
    |----------------------------------------------------------------------
    | 不動産 契約管理 Ajax API（3ルート）
    |----------------------------------------------------------------------
    |
    | web.php の認証ルート内（realestate prefix の外）に追加
    |
    */

    Route::get('/api/realestate/procurement-cost/{procurement}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'getProcurementCost'])
        ->middleware('department.access:realestate')->name('api.realestate.procurement-cost');
    Route::get('/api/realestate/project-lots/{project}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'getProjectLots'])
        ->middleware('department.access:realestate')->name('api.realestate.project-lots');
    Route::get('/api/realestate/project-lot-cost/{project}', [\App\Http\Controllers\RealEstate\ReContractController::class, 'getProjectLotCost'])
        ->middleware('department.access:realestate')->name('api.realestate.project-lot-cost');
    
    
    /*
    |----------------------------------------------------------------------
    | 不動産 分譲地管理（7 + 1 + 3 + 3 + 2 = 16ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('realestate')->middleware('department.access:realestate')->group(function () {
        Route::get('/projects', [\App\Http\Controllers\RealEstate\ProjectController::class, 'index'])
            ->name('realestate.projects.index');

        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/projects/create', [\App\Http\Controllers\RealEstate\ProjectController::class, 'create'])
                ->name('realestate.projects.create');
            Route::post('/projects', [\App\Http\Controllers\RealEstate\ProjectController::class, 'store'])
                ->name('realestate.projects.store');
        });

        Route::get('/projects/{project}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'show'])
            ->name('realestate.projects.show');

        Route::get('/projects/{project}/lots', [\App\Http\Controllers\RealEstate\ProjectController::class, 'lots'])
            ->name('realestate.projects.lots');

        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/projects/{project}/edit', [\App\Http\Controllers\RealEstate\ProjectController::class, 'edit'])
                ->name('realestate.projects.edit');
            Route::put('/projects/{project}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'update'])
                ->name('realestate.projects.update');
            // 一覧バッジクリックからのステータスのみ Ajax 更新
            Route::patch('/projects/{project}/status', [\App\Http\Controllers\RealEstate\ProjectController::class, 'updateStatus'])
                ->name('realestate.projects.update-status');
        });

        Route::delete('/projects/{project}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.projects.destroy');

        // PJ原価管理 Ajax
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/projects/{project}/costs', [\App\Http\Controllers\RealEstate\ProjectController::class, 'storeCost'])
                ->name('realestate.projects.costs.store');
            Route::put('/projects/{project}/costs/{cost}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'updateCost'])
                ->name('realestate.projects.costs.update');
            // 試算表 Excel/CSV 取込（バルク投入）
            Route::post('/projects/{project}/costs/bulk-import', [\App\Http\Controllers\RealEstate\ProjectController::class, 'bulkImportCosts'])
                ->name('realestate.projects.costs.bulk-import');
        });
        Route::delete('/projects/{project}/costs/{cost}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'destroyCost'])
            ->middleware('role:executive')
            ->name('realestate.projects.costs.destroy');

        // PJ区画管理 Ajax
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/projects/{project}/lots', [\App\Http\Controllers\RealEstate\ProjectController::class, 'storeLot'])
                ->name('realestate.projects.lots.store');
            Route::put('/projects/{project}/lots/{lot}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'updateLot'])
                ->name('realestate.projects.lots.update');
        });
        Route::delete('/projects/{project}/lots/{lot}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'destroyLot'])
            ->middleware('role:executive')
            ->name('realestate.projects.lots.destroy');

        // PJ図面管理 Ajax
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/projects/{project}/drawings', [\App\Http\Controllers\RealEstate\ProjectController::class, 'storeDrawing'])
                ->name('realestate.projects.drawings.store');
        });
        Route::delete('/projects/{project}/drawings/{drawing}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'destroyDrawing'])
            ->middleware('role:executive')
            ->name('realestate.projects.drawings.destroy');
        // 図面表示（symlink に依存せず Laravel 経由でストリーミング配信）
        Route::get('/projects/{project}/drawings/{drawing}', [\App\Http\Controllers\RealEstate\ProjectController::class, 'showDrawing'])
            ->name('realestate.projects.drawings.show');
    });

    /*
    |----------------------------------------------------------------------
    | 不動産 仕入れ先 Ajax 検索 + 簡易登録（2ルート）
    |----------------------------------------------------------------------
    */
    Route::get('/api/realestate/suppliers/search', [\App\Http\Controllers\RealEstate\SupplierController::class, 'search'])
        ->middleware('department.access:realestate')->name('api.realestate.suppliers.search');

    Route::post('/api/realestate/suppliers/quick', [\App\Http\Controllers\RealEstate\SupplierController::class, 'quickStore'])
        ->middleware('role:executive,manager')
        ->middleware('department.access:realestate')->name('api.realestate.suppliers.quick');

    /*
    |----------------------------------------------------------------------
    | 原価項目マスタ（5ルート）※経営層のみ — システム管理内
    |----------------------------------------------------------------------
    */
    Route::middleware('role:executive')->prefix('admin')->group(function () {
        Route::post('/master/re-cost-items/reorder', [\App\Http\Controllers\Admin\ReCostItemController::class, 'reorder'])
            ->name('admin.master.re-cost-items.reorder');
        Route::get('/master/re-cost-items', [\App\Http\Controllers\Admin\ReCostItemController::class, 'index'])
            ->name('admin.master.re-cost-items.index');
        Route::post('/master/re-cost-items', [\App\Http\Controllers\Admin\ReCostItemController::class, 'store'])
            ->name('admin.master.re-cost-items.store');
        Route::put('/master/re-cost-items/{costItem}', [\App\Http\Controllers\Admin\ReCostItemController::class, 'update'])
            ->name('admin.master.re-cost-items.update');
        Route::delete('/master/re-cost-items/{costItem}', [\App\Http\Controllers\Admin\ReCostItemController::class, 'destroy'])
            ->name('admin.master.re-cost-items.destroy');
    });
    
    /*
    |----------------------------------------------------------------------
    | 住宅事業 建売物件管理（7ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('housing')->middleware('department.access:housing')->group(function () {
        // 住宅事業ダッシュボード（建売 + 注文住宅 成約フォーカス）
        Route::get('/', [\App\Http\Controllers\Housing\HousingDashboardController::class, 'index'])
            ->name('housing.dashboard');

        /*
        |------------------------------------------------------------------
        | 住宅事業 契約管理 統合（8ルート）
        |   建売・注文住宅を横断する契約管理画面。URLは /housing/contracts/{type}/{id}
        |   type = building | custom-order
        |------------------------------------------------------------------
        */
        // 契約一覧（全ロール閲覧可）
        Route::get('/contracts', [\App\Http\Controllers\Housing\HsContractListController::class, 'index'])
            ->name('housing.contracts.index');

        // 建売物件選択画面（建売契約新規登録の第一段階、全ロール閲覧可）
        Route::get('/contracts/create/building/select-property', [\App\Http\Controllers\Housing\HsContractListController::class, 'selectBuildingProperty'])
            ->name('housing.contracts.select-building-property');

        // 建売契約詳細（全ロール閲覧可）
        Route::get('/contracts/building/{hsContract}', [\App\Http\Controllers\Housing\HsContractListController::class, 'showBuilding'])
            ->name('housing.contracts.show-building');

        // 注文住宅契約詳細（全ロール閲覧可）
        Route::get('/contracts/custom-order/{hsCustomOrder}', [\App\Http\Controllers\Housing\HsContractListController::class, 'showCustomOrder'])
            ->name('housing.contracts.show-custom-order');

        // 契約編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/contracts/building/{hsContract}/edit', [\App\Http\Controllers\Housing\HsContractListController::class, 'editBuilding'])
                ->name('housing.contracts.edit-building');
            Route::put('/contracts/building/{hsContract}', [\App\Http\Controllers\Housing\HsContractListController::class, 'updateBuilding'])
                ->name('housing.contracts.update-building');
            Route::get('/contracts/custom-order/{hsCustomOrder}/edit', [\App\Http\Controllers\Housing\HsContractListController::class, 'editCustomOrder'])
                ->name('housing.contracts.edit-custom-order');
            Route::put('/contracts/custom-order/{hsCustomOrder}', [\App\Http\Controllers\Housing\HsContractListController::class, 'updateCustomOrder'])
                ->name('housing.contracts.update-custom-order');
        });

        // 建売物件一覧（全ロール閲覧可）
        Route::get('/properties', [\App\Http\Controllers\Housing\PropertyController::class, 'index'])
            ->name('housing.properties.index');

        // 建売物件登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/create', [\App\Http\Controllers\Housing\PropertyController::class, 'create'])
                ->name('housing.properties.create');
            Route::post('/properties', [\App\Http\Controllers\Housing\PropertyController::class, 'store'])
                ->name('housing.properties.store');
        });

        // 建売物件詳細（全ロール閲覧可）
        Route::get('/properties/{property}', [\App\Http\Controllers\Housing\PropertyController::class, 'show'])
            ->name('housing.properties.show');

        // 建売物件編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/edit', [\App\Http\Controllers\Housing\PropertyController::class, 'edit'])
                ->name('housing.properties.edit');
            Route::put('/properties/{property}', [\App\Http\Controllers\Housing\PropertyController::class, 'update'])
                ->name('housing.properties.update');
            // 一覧バッジクリックからの進捗ステータスのみ Ajax 更新
            Route::patch('/properties/{property}/status', [\App\Http\Controllers\Housing\PropertyController::class, 'updateStatus'])
                ->name('housing.properties.update-status');
        });

        // 建売物件削除（経営層のみ）
        Route::delete('/properties/{property}', [\App\Http\Controllers\Housing\PropertyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('housing.properties.destroy');

        /*
        |------------------------------------------------------------------
        | 建売契約管理（5ルート）
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/properties/{property}/contract/create', [\App\Http\Controllers\Housing\ContractController::class, 'create'])
                ->name('housing.contracts.create');
            Route::post('/properties/{property}/contract', [\App\Http\Controllers\Housing\ContractController::class, 'store'])
                ->name('housing.contracts.store');
            Route::get('/properties/{property}/contract/edit', [\App\Http\Controllers\Housing\ContractController::class, 'edit'])
                ->name('housing.contracts.edit');
            Route::put('/properties/{property}/contract', [\App\Http\Controllers\Housing\ContractController::class, 'update'])
                ->name('housing.contracts.update');
        });

        // 契約削除（経営層のみ）
        Route::delete('/properties/{property}/contract', [\App\Http\Controllers\Housing\ContractController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('housing.contracts.destroy');

        /*
        |------------------------------------------------------------------
        | 建売ファイル管理 Ajax（2ルート）
        | ※ URL に /files を含むと Sakura WAF にブロックされるため /documents を使用
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/properties/{property}/documents', [\App\Http\Controllers\Housing\PropertyController::class, 'storeFile'])
                ->name('housing.properties.files.store');
        });
        Route::delete('/properties/{property}/documents/{file}', [\App\Http\Controllers\Housing\PropertyController::class, 'destroyFile'])
            ->middleware('role:executive')
            ->name('housing.properties.files.destroy');
        // ファイル閲覧（本番のシンボリックリンク経由は 403 になるため Laravel 経由で stream 配信）
        Route::get('/properties/{property}/documents/{file}', [\App\Http\Controllers\Housing\PropertyController::class, 'showFile'])
            ->name('housing.properties.files.show');
    });

    /*
    |----------------------------------------------------------------------
    | 住宅事業 Ajax API（2ルート）
    |----------------------------------------------------------------------
    */
    Route::get('/api/housing/project-lots', [\App\Http\Controllers\Housing\PropertyController::class, 'projectLots'])
        ->middleware('department.access:housing')->name('api.housing.project-lots');
    Route::get('/api/housing/procurement-info/{procurement}', [\App\Http\Controllers\Housing\PropertyController::class, 'procurementInfo'])
        ->middleware('department.access:housing')->name('api.housing.procurement-info');
        
    /*
    |----------------------------------------------------------------------
    | 住宅事業 注文住宅管理（7ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('housing')->middleware('department.access:housing')->group(function () {
        // 注文住宅一覧（全ロール閲覧可）
        Route::get('/custom-orders', [\App\Http\Controllers\Housing\CustomOrderController::class, 'index'])
            ->name('housing.custom-orders.index');

        // 注文住宅登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/custom-orders/create', [\App\Http\Controllers\Housing\CustomOrderController::class, 'create'])
                ->name('housing.custom-orders.create');
            Route::post('/custom-orders', [\App\Http\Controllers\Housing\CustomOrderController::class, 'store'])
                ->name('housing.custom-orders.store');
        });

        // 注文住宅詳細（全ロール閲覧可）
        Route::get('/custom-orders/{customOrder}', [\App\Http\Controllers\Housing\CustomOrderController::class, 'show'])
            ->name('housing.custom-orders.show');

        // 注文住宅編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/custom-orders/{customOrder}/edit', [\App\Http\Controllers\Housing\CustomOrderController::class, 'edit'])
                ->name('housing.custom-orders.edit');
            Route::put('/custom-orders/{customOrder}', [\App\Http\Controllers\Housing\CustomOrderController::class, 'update'])
                ->name('housing.custom-orders.update');
        });

        // 注文住宅削除（経営層のみ）
        Route::delete('/custom-orders/{customOrder}', [\App\Http\Controllers\Housing\CustomOrderController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('housing.custom-orders.destroy');

        /*
        |------------------------------------------------------------------
        | 注文住宅 ステータス変更 Ajax（1ルート）
        |------------------------------------------------------------------
        */
        Route::patch('/custom-orders/{customOrder}/status', [\App\Http\Controllers\Housing\CustomOrderController::class, 'updateStatus'])
            ->middleware('role:executive,manager')
            ->name('housing.custom-orders.update-status');

        /*
        |------------------------------------------------------------------
        | 注文住宅ファイル管理 Ajax（2ルート）
        | ※ URL に /files を含むと Sakura WAF にブロックされるため /documents を使用
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/custom-orders/{customOrder}/documents', [\App\Http\Controllers\Housing\CustomOrderController::class, 'storeFile'])
                ->name('housing.custom-orders.files.store');
        });
        Route::delete('/custom-orders/{customOrder}/documents/{file}', [\App\Http\Controllers\Housing\CustomOrderController::class, 'destroyFile'])
            ->middleware('role:executive')
            ->name('housing.custom-orders.files.destroy');
        // ファイル閲覧（本番のシンボリックリンク経由は 403 になるため Laravel 経由で stream 配信）
        Route::get('/custom-orders/{customOrder}/documents/{file}', [\App\Http\Controllers\Housing\CustomOrderController::class, 'showFile'])
            ->name('housing.custom-orders.files.show');
    });
    
    /*
    |----------------------------------------------------------------------
    | 顧客管理 買主マスタ — 住宅事業（7ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('housing')->middleware('department.access:housing')->group(function () {
        // 顧客一覧（全ロール閲覧可）
        Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'index'])
            ->name('housing.customers.index');

        // 顧客登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/create', [\App\Http\Controllers\CustomerController::class, 'create'])
                ->name('housing.customers.create');
            Route::post('/customers', [\App\Http\Controllers\CustomerController::class, 'store'])
                ->name('housing.customers.store');
            // フェーズ2: 契約フォームからの買主クイック登録（Ajax）
            Route::post('/customers/quick-store', [\App\Http\Controllers\CustomerController::class, 'quickStore'])
                ->name('housing.customers.quick-store');
        });

        // 顧客詳細（全ロール閲覧可）
        Route::get('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'show'])
            ->name('housing.customers.show');

        // 顧客編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/{buyer}/edit', [\App\Http\Controllers\CustomerController::class, 'edit'])
                ->name('housing.customers.edit');
            Route::put('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'update'])
                ->name('housing.customers.update');
        });

        // 顧客削除（経営層のみ）
        Route::delete('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('housing.customers.destroy');

        /*
        |------------------------------------------------------------------
        | アンケート管理 — 住宅事業（5ルート）
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/{buyer}/surveys/create', [\App\Http\Controllers\CustomerSurveyController::class, 'create'])
                ->name('housing.customers.surveys.create');
            Route::post('/customers/{buyer}/surveys', [\App\Http\Controllers\CustomerSurveyController::class, 'store'])
                ->name('housing.customers.surveys.store');
            Route::get('/customers/{buyer}/surveys/{survey}/edit', [\App\Http\Controllers\CustomerSurveyController::class, 'edit'])
                ->name('housing.customers.surveys.edit');
            Route::put('/customers/{buyer}/surveys/{survey}', [\App\Http\Controllers\CustomerSurveyController::class, 'update'])
                ->name('housing.customers.surveys.update');
        });
        Route::delete('/customers/{buyer}/surveys/{survey}', [\App\Http\Controllers\CustomerSurveyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('housing.customers.surveys.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | 顧客管理 買主マスタ — 不動産事業（7ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('realestate')->middleware('department.access:realestate')->group(function () {
        // 顧客一覧（全ロール閲覧可）
        Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'index'])
            ->name('realestate.customers.index');

        // 顧客登録（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/create', [\App\Http\Controllers\CustomerController::class, 'create'])
                ->name('realestate.customers.create');
            Route::post('/customers', [\App\Http\Controllers\CustomerController::class, 'store'])
                ->name('realestate.customers.store');
        });

        // 顧客詳細（全ロール閲覧可）
        Route::get('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'show'])
            ->name('realestate.customers.show');

        // 顧客編集・更新（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/{buyer}/edit', [\App\Http\Controllers\CustomerController::class, 'edit'])
                ->name('realestate.customers.edit');
            Route::put('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'update'])
                ->name('realestate.customers.update');
        });

        // 顧客削除（経営層のみ）
        Route::delete('/customers/{buyer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.customers.destroy');

        /*
        |------------------------------------------------------------------
        | アンケート管理 — 不動産事業（5ルート）
        |------------------------------------------------------------------
        */
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/customers/{buyer}/surveys/create', [\App\Http\Controllers\CustomerSurveyController::class, 'create'])
                ->name('realestate.customers.surveys.create');
            Route::post('/customers/{buyer}/surveys', [\App\Http\Controllers\CustomerSurveyController::class, 'store'])
                ->name('realestate.customers.surveys.store');
            Route::get('/customers/{buyer}/surveys/{survey}/edit', [\App\Http\Controllers\CustomerSurveyController::class, 'edit'])
                ->name('realestate.customers.surveys.edit');
            Route::put('/customers/{buyer}/surveys/{survey}', [\App\Http\Controllers\CustomerSurveyController::class, 'update'])
                ->name('realestate.customers.surveys.update');
        });
        Route::delete('/customers/{buyer}/surveys/{survey}', [\App\Http\Controllers\CustomerSurveyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('realestate.customers.surveys.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | DAD管理（Phase B〜G で段階実装）
    |----------------------------------------------------------------------
    */
    Route::prefix('dad')->middleware('department.access:dad')->group(function () {
        // 発注者管理（7ルート）
        Route::get('/clients', [\App\Http\Controllers\Dad\ClientController::class, 'index'])
            ->name('dad.clients.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/clients/create', [\App\Http\Controllers\Dad\ClientController::class, 'create'])
                ->name('dad.clients.create');
            Route::post('/clients', [\App\Http\Controllers\Dad\ClientController::class, 'store'])
                ->name('dad.clients.store');
            Route::get('/clients/{client}/edit', [\App\Http\Controllers\Dad\ClientController::class, 'edit'])
                ->name('dad.clients.edit');
            Route::put('/clients/{client}', [\App\Http\Controllers\Dad\ClientController::class, 'update'])
                ->name('dad.clients.update');
        });
        Route::get('/clients/{client}', [\App\Http\Controllers\Dad\ClientController::class, 'show'])
            ->name('dad.clients.show');
        Route::delete('/clients/{client}', [\App\Http\Controllers\Dad\ClientController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('dad.clients.destroy');

        // 協力業者管理（6ルート）
        Route::get('/subcontractors', [\App\Http\Controllers\Dad\SubcontractorController::class, 'index'])
            ->name('dad.subcontractors.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/subcontractors/create', [\App\Http\Controllers\Dad\SubcontractorController::class, 'create'])
                ->name('dad.subcontractors.create');
            Route::post('/subcontractors', [\App\Http\Controllers\Dad\SubcontractorController::class, 'store'])
                ->name('dad.subcontractors.store');
            Route::get('/subcontractors/{subcontractor}/edit', [\App\Http\Controllers\Dad\SubcontractorController::class, 'edit'])
                ->name('dad.subcontractors.edit');
            Route::put('/subcontractors/{subcontractor}', [\App\Http\Controllers\Dad\SubcontractorController::class, 'update'])
                ->name('dad.subcontractors.update');
        });
        Route::get('/subcontractors/{subcontractor}', [\App\Http\Controllers\Dad\SubcontractorController::class, 'show'])
            ->name('dad.subcontractors.show');
        Route::delete('/subcontractors/{subcontractor}', [\App\Http\Controllers\Dad\SubcontractorController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('dad.subcontractors.destroy');

        // 従業員管理（6ルート）
        Route::get('/employees', [\App\Http\Controllers\Dad\EmployeeController::class, 'index'])
            ->name('dad.employees.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/employees/create', [\App\Http\Controllers\Dad\EmployeeController::class, 'create'])
                ->name('dad.employees.create');
            Route::post('/employees', [\App\Http\Controllers\Dad\EmployeeController::class, 'store'])
                ->name('dad.employees.store');
            Route::get('/employees/{employee}/edit', [\App\Http\Controllers\Dad\EmployeeController::class, 'edit'])
                ->name('dad.employees.edit');
            Route::put('/employees/{employee}', [\App\Http\Controllers\Dad\EmployeeController::class, 'update'])
                ->name('dad.employees.update');
        });
        Route::get('/employees/{employee}', [\App\Http\Controllers\Dad\EmployeeController::class, 'show'])
            ->name('dad.employees.show');
        Route::delete('/employees/{employee}', [\App\Http\Controllers\Dad\EmployeeController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('dad.employees.destroy');

        // 工事案件管理（7ルート）
        Route::get('/projects', [\App\Http\Controllers\Dad\ProjectController::class, 'index'])
            ->name('dad.projects.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/projects/create', [\App\Http\Controllers\Dad\ProjectController::class, 'create'])
                ->name('dad.projects.create');
            Route::post('/projects', [\App\Http\Controllers\Dad\ProjectController::class, 'store'])
                ->name('dad.projects.store');
            Route::get('/projects/{project}/edit', [\App\Http\Controllers\Dad\ProjectController::class, 'edit'])
                ->name('dad.projects.edit');
            Route::put('/projects/{project}', [\App\Http\Controllers\Dad\ProjectController::class, 'update'])
                ->name('dad.projects.update');
        });
        Route::get('/projects/{project}', [\App\Http\Controllers\Dad\ProjectController::class, 'show'])
            ->name('dad.projects.show');
        Route::delete('/projects/{project}', [\App\Http\Controllers\Dad\ProjectController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('dad.projects.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | ZEAL フィットネス事業（Phase 3-B〜3-G で段階実装）
    |----------------------------------------------------------------------
    */
    Route::prefix('zeal')->middleware('department.access:zeal')->group(function () {
        // ダッシュボード（1ルート）
        Route::get('/', [\App\Http\Controllers\Zeal\DashboardController::class, 'index'])
            ->name('zeal.dashboard');

        // 体験予約閲覧（2ルート — 外部DB参照のみ、書き込み不可）
        Route::get('/inquiries', [\App\Http\Controllers\Zeal\InquiryController::class, 'index'])
            ->name('zeal.inquiries.index');
        Route::get('/inquiries/{inquiry}', [\App\Http\Controllers\Zeal\InquiryController::class, 'show'])
            ->name('zeal.inquiries.show');

        // 会員管理（9ルート）
        Route::get('/members', [\App\Http\Controllers\Zeal\MemberController::class, 'index'])
            ->name('zeal.members.index');
        Route::get('/members/{member}', [\App\Http\Controllers\Zeal\MemberController::class, 'show'])
            ->name('zeal.members.show');
        Route::middleware('role:executive,manager')->group(function () {
            // 新規登録は CSV インポート（Admin\ZealMemberImportController）経由のみ。
            // create / store ルートは要件上不要のため定義しない。
            Route::get('/members/{member}/edit', [\App\Http\Controllers\Zeal\MemberController::class, 'edit'])
                ->name('zeal.members.edit');
            Route::put('/members/{member}', [\App\Http\Controllers\Zeal\MemberController::class, 'update'])
                ->name('zeal.members.update');
            Route::post('/members/{member}/change-plan', [\App\Http\Controllers\Zeal\MemberController::class, 'changePlan'])
                ->name('zeal.members.change-plan');
            Route::post('/members/{member}/withdraw', [\App\Http\Controllers\Zeal\MemberController::class, 'withdraw'])
                ->name('zeal.members.withdraw');
        });
        Route::delete('/members/{member}', [\App\Http\Controllers\Zeal\MemberController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.members.destroy');

        // プランマスタ（5ルート）
        Route::get('/plans', [\App\Http\Controllers\Zeal\PlanController::class, 'index'])
            ->name('zeal.plans.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/plans/create', [\App\Http\Controllers\Zeal\PlanController::class, 'create'])
                ->name('zeal.plans.create');
            Route::post('/plans', [\App\Http\Controllers\Zeal\PlanController::class, 'store'])
                ->name('zeal.plans.store');
            Route::get('/plans/{plan}/edit', [\App\Http\Controllers\Zeal\PlanController::class, 'edit'])
                ->name('zeal.plans.edit');
            Route::put('/plans/{plan}', [\App\Http\Controllers\Zeal\PlanController::class, 'update'])
                ->name('zeal.plans.update');
        });
        Route::delete('/plans/{plan}', [\App\Http\Controllers\Zeal\PlanController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.plans.destroy');

        // トレーナーマスタ（4ルート — Ajax CRUD）
        Route::get('/trainers', [\App\Http\Controllers\Zeal\TrainerController::class, 'index'])
            ->name('zeal.trainers.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/trainers', [\App\Http\Controllers\Zeal\TrainerController::class, 'store'])
                ->name('zeal.trainers.store');
            Route::put('/trainers/{trainer}', [\App\Http\Controllers\Zeal\TrainerController::class, 'update'])
                ->name('zeal.trainers.update');
        });
        Route::delete('/trainers/{trainer}', [\App\Http\Controllers\Zeal\TrainerController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.trainers.destroy');

        // 店舗マスタ（4ルート — Ajax CRUD）
        Route::get('/stores', [\App\Http\Controllers\Zeal\StoreController::class, 'index'])
            ->name('zeal.stores.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/stores', [\App\Http\Controllers\Zeal\StoreController::class, 'store'])
                ->name('zeal.stores.store');
            Route::put('/stores/{store}', [\App\Http\Controllers\Zeal\StoreController::class, 'update'])
                ->name('zeal.stores.update');
        });
        Route::delete('/stores/{store}', [\App\Http\Controllers\Zeal\StoreController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.stores.destroy');

        /*
        |------------------------------------------------------------------
        | ZEAL 経営試算表（10 ルート — CRUD + sync-actuals + 本部 Sheet 取り込み）
        |------------------------------------------------------------------
        */
        // 経営試算表は機密財務データのため経営層・管理者のみ閲覧可
        Route::middleware('role:executive,manager')->group(function () {
            Route::get('/simulations', [\App\Http\Controllers\Zeal\SimulationController::class, 'index'])
                ->name('zeal.simulations.index');
            Route::get('/simulations/create', [\App\Http\Controllers\Zeal\SimulationController::class, 'create'])
                ->name('zeal.simulations.create');
            Route::post('/simulations', [\App\Http\Controllers\Zeal\SimulationController::class, 'store'])
                ->name('zeal.simulations.store');
            Route::get('/simulations/{simulation}/edit', [\App\Http\Controllers\Zeal\SimulationController::class, 'edit'])
                ->name('zeal.simulations.edit');
            Route::put('/simulations/{simulation}', [\App\Http\Controllers\Zeal\SimulationController::class, 'update'])
                ->name('zeal.simulations.update');
            // Phase 4: 実績連動（売上・会員数を zeal_members / zeal_member_contracts から取り込む）
            Route::get('/simulations/{simulation}/sync-actuals/preview', [\App\Http\Controllers\Zeal\SimulationController::class, 'syncActualsPreview'])
                ->name('zeal.simulations.sync-actuals.preview');
            Route::post('/simulations/{simulation}/sync-actuals', [\App\Http\Controllers\Zeal\SimulationController::class, 'syncActuals'])
                ->name('zeal.simulations.sync-actuals');
            // 本部 Google Sheets 連携: URL 設定 + 取り込みプレビュー/反映
            Route::get('/simulations/{simulation}/sheet-urls/edit', [\App\Http\Controllers\Zeal\SheetImportController::class, 'editUrls'])
                ->name('zeal.simulations.sheet-urls.edit');
            Route::put('/simulations/{simulation}/sheet-urls', [\App\Http\Controllers\Zeal\SheetImportController::class, 'updateUrls'])
                ->name('zeal.simulations.sheet-urls.update');
            Route::post('/simulations/{simulation}/sheet-import/preview', [\App\Http\Controllers\Zeal\SheetImportController::class, 'preview'])
                ->name('zeal.simulations.sheet-import.preview');
            Route::post('/simulations/{simulation}/sheet-import/apply', [\App\Http\Controllers\Zeal\SheetImportController::class, 'apply'])
                ->name('zeal.simulations.sheet-import.apply');
            Route::get('/simulations/{simulation}', [\App\Http\Controllers\Zeal\SimulationController::class, 'show'])
                ->name('zeal.simulations.show');
        });
        Route::delete('/simulations/{simulation}', [\App\Http\Controllers\Zeal\SimulationController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.simulations.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | 顧客管理 Ajax API（3ルート — 共通）
    |----------------------------------------------------------------------
    */
    // ランク変更Ajax
    Route::patch('/api/customers/{buyer}/rank', [\App\Http\Controllers\CustomerController::class, 'updateRank'])
        ->middleware('role:executive,manager')
        ->middleware('department.access')->name('api.customers.rank.update');

    // 重複チェックAjax（登録と同じ認可ライン: 経営層+管理者 ＋ 自部署所属）
    Route::post('/api/customers/check-duplicate', [\App\Http\Controllers\CustomerController::class, 'checkDuplicate'])
        ->middleware('role:executive,manager')
        ->middleware('department.access')
        ->name('api.customers.check-duplicate');

    // 他部署追加Ajax
    Route::post('/api/customers/{buyer}/add-department', [\App\Http\Controllers\CustomerController::class, 'addToDepartment'])
        ->middleware('role:executive,manager')
        ->middleware('department.access')->name('api.customers.add-department');
        
    /*
    |----------------------------------------------------------------------
    | アンケート設問管理（マスタ管理 — 5ルート）※経営層のみ
    |----------------------------------------------------------------------
    */
    Route::middleware('role:executive')->prefix('admin')->group(function () {
        Route::get('/survey-questions', [\App\Http\Controllers\Admin\SurveyQuestionController::class, 'index'])
            ->name('admin.survey-questions.index');

        // Ajax CRUD
        Route::post('/survey-questions', [\App\Http\Controllers\Admin\SurveyQuestionController::class, 'store'])
            ->name('admin.survey-questions.store');
        Route::put('/survey-questions/{question}', [\App\Http\Controllers\Admin\SurveyQuestionController::class, 'update'])
            ->name('admin.survey-questions.update');
        Route::delete('/survey-questions/{question}', [\App\Http\Controllers\Admin\SurveyQuestionController::class, 'destroy'])
            ->name('admin.survey-questions.destroy');
        Route::post('/survey-questions/reorder', [\App\Http\Controllers\Admin\SurveyQuestionController::class, 'reorder'])
            ->name('admin.survey-questions.reorder');
    });

    /*
    |----------------------------------------------------------------------
    | 顧客CSVインポート（管理画面 — 3ルート）※経営層のみ
    |----------------------------------------------------------------------
    */
    Route::middleware('role:executive')->prefix('admin')->group(function () {
        Route::get('/customers/import', [\App\Http\Controllers\Admin\CustomerImportController::class, 'showForm'])
            ->name('admin.customers.import');
        Route::post('/customers/import', [\App\Http\Controllers\Admin\CustomerImportController::class, 'execute'])
            ->name('admin.customers.import.execute');
        Route::get('/customers/import/template', [\App\Http\Controllers\Admin\CustomerImportController::class, 'downloadTemplate'])
            ->name('admin.customers.import.template');

        // テナントCSVインポート（4種別: 物件・区画・顧客・契約）
        Route::get('/tenant-import', [\App\Http\Controllers\Admin\TenantImportController::class, 'showForm'])
            ->name('admin.tenant-import');
        Route::post('/tenant-import/property', [\App\Http\Controllers\Admin\TenantImportController::class, 'executeProperty'])
            ->name('admin.tenant-import.property');
        Route::post('/tenant-import/unit', [\App\Http\Controllers\Admin\TenantImportController::class, 'executeUnit'])
            ->name('admin.tenant-import.unit');
        Route::post('/tenant-import/customer', [\App\Http\Controllers\Admin\TenantImportController::class, 'executeCustomer'])
            ->name('admin.tenant-import.customer');
        Route::post('/tenant-import/contract', [\App\Http\Controllers\Admin\TenantImportController::class, 'executeContract'])
            ->name('admin.tenant-import.contract');
        Route::post('/tenant-import/past-contract', [\App\Http\Controllers\Admin\TenantImportController::class, 'executePastContract'])
            ->name('admin.tenant-import.past-contract');
        Route::get('/tenant-import/template/property', [\App\Http\Controllers\Admin\TenantImportController::class, 'downloadPropertyTemplate'])
            ->name('admin.tenant-import.template.property');
        Route::get('/tenant-import/template/unit', [\App\Http\Controllers\Admin\TenantImportController::class, 'downloadUnitTemplate'])
            ->name('admin.tenant-import.template.unit');
        Route::get('/tenant-import/template/customer', [\App\Http\Controllers\Admin\TenantImportController::class, 'downloadCustomerTemplate'])
            ->name('admin.tenant-import.template.customer');
        Route::get('/tenant-import/template/contract', [\App\Http\Controllers\Admin\TenantImportController::class, 'downloadContractTemplate'])
            ->name('admin.tenant-import.template.contract');
        Route::get('/tenant-import/template/past-contract', [\App\Http\Controllers\Admin\TenantImportController::class, 'downloadPastContractTemplate'])
            ->name('admin.tenant-import.template.past-contract');

        // 賃貸マンションCSVインポート（6種別: 物件・部屋・駐車場・入居者・部屋契約・駐車場契約）
        Route::get('/mansion-import', [\App\Http\Controllers\Admin\MansionImportController::class, 'showForm'])
            ->name('admin.mansion-import');
        Route::post('/mansion-import/property', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeProperty'])
            ->name('admin.mansion-import.execute-property');
        Route::post('/mansion-import/room', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeRoom'])
            ->name('admin.mansion-import.execute-room');
        Route::post('/mansion-import/parking', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeParking'])
            ->name('admin.mansion-import.execute-parking');
        Route::post('/mansion-import/tenant', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeTenant'])
            ->name('admin.mansion-import.execute-tenant');
        Route::post('/mansion-import/room-contract', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeRoomContract'])
            ->name('admin.mansion-import.execute-room-contract');
        Route::post('/mansion-import/parking-contract', [\App\Http\Controllers\Admin\MansionImportController::class, 'executeParkingContract'])
            ->name('admin.mansion-import.execute-parking-contract');
        Route::get('/mansion-import/template/property', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadPropertyTemplate'])
            ->name('admin.mansion-import.template-property');
        Route::get('/mansion-import/template/room', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadRoomTemplate'])
            ->name('admin.mansion-import.template-room');
        Route::get('/mansion-import/template/parking', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadParkingTemplate'])
            ->name('admin.mansion-import.template-parking');
        Route::get('/mansion-import/template/tenant', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadTenantTemplate'])
            ->name('admin.mansion-import.template-tenant');
        Route::get('/mansion-import/template/room-contract', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadRoomContractTemplate'])
            ->name('admin.mansion-import.template-room-contract');
        Route::get('/mansion-import/template/parking-contract', [\App\Http\Controllers\Admin\MansionImportController::class, 'downloadParkingContractTemplate'])
            ->name('admin.mansion-import.template-parking-contract');

        // ZEAL 会員 CSV インポート（3ルート）
        Route::get('/zeal/member-import', [\App\Http\Controllers\Admin\ZealMemberImportController::class, 'index'])
            ->name('admin.zeal.member-import');
        Route::post('/zeal/member-import/preview', [\App\Http\Controllers\Admin\ZealMemberImportController::class, 'preview'])
            ->name('admin.zeal.member-import.preview');
        Route::post('/zeal/member-import/execute', [\App\Http\Controllers\Admin\ZealMemberImportController::class, 'execute'])
            ->name('admin.zeal.member-import.execute');
    });

    /*
    |----------------------------------------------------------------------
    | 以降のルートはSTEP 12以降で追加
    |----------------------------------------------------------------------
    */
});
