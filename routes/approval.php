<?php

use App\Http\Controllers\Approval\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 決裁申請（段階1 で 1 ルート。段階2 以降で増える）
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
