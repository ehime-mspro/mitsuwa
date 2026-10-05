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
}
