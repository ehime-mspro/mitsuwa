<?php

namespace Tests\Concerns;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * ルートの URI にパラメータの値を埋めて、実際に叩ける URL を組み立てる。
 *
 * ⚠ 2026-09-18 に `Tests\Feature\Approval\ApprovalOnlyLockoutTest` と
 *   `Tests\Feature\Approval\ApprovalAdminGateTest` の重複から切り出した。値の決め方
 *   （`where` の条件から取るか、固定の実在値を使うか等）はテストごとに違うので、
 *   ここでは持たず呼び出し側の resolver（`callable(string $name): string`）に委ねる。
 *   複製すると drift するので、中身を変えるときは両方の利用者で測り直すこと。
 */
trait BuildsRouteUrls
{
    /**
     * `{name}` と `{name?}`（省略可能パラメータ）の両方を、$valueFor が返す値へ置き換える。
     *
     * @param callable(string): string $valueFor
     */
    private function urlForRoute(RoutingRoute $route, callable $valueFor): string
    {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $name) {
            $uri = str_replace(['{' . $name . '}', '{' . $name . '?}'], (string) $valueFor($name), $uri);
        }

        return '/' . ltrim($uri, '/');
    }
}
