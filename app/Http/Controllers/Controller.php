<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    /**
     * 一覧の絞り込みなどのクエリのうち、文字列でない値（手で組んだ `?x[]=1`）と、$digits に挙げたキーの数字でない値を
     * 無かったことにする（Bug #97 の形）。
     * 画面（`request('x')`）とページ送り（withQueryString）も同じ Request を読むので、ここで外せばどちらも 500 にならない。
     * ⚠ `$request->query('x')` は配列のとき例外を投げるので、`all()` から読む。
     *
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $digits
     */
    protected function ignoreMalformedQuery(Request $request, array $keys, array $digits = []): void
    {
        $query = $request->query->all();
        foreach (array_merge($keys, $digits) as $key) {
            if (! array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            if (! is_string($value) || (in_array($key, $digits, true) && ! ctype_digit($value))) {
                $request->query->remove($key);
            }
        }
    }
}
