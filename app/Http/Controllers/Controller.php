<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * MySQL の符号付き INT の上限。金額などを INT の列に入れる入力チェックの `max:` に使う
     * （本番の MySQL は strict なので、列に入らない値は保存の時点で 500 になる。Bug #73）。
     */
    protected const MAX_INT_COLUMN = 2147483647;

    /**
     * MySQL の符号なし INT（INT UNSIGNED）の上限。賃貸マンション（`ms_*`）の金額の列はどれも INT UNSIGNED
     * （2026-10-07 に読み取りで確認）。
     */
    protected const MAX_UNSIGNED_INT_COLUMN = 4294967295;
}
