<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * テストでも CSRF の鍵を確かめる（Laravel の ValidateCsrfToken はテストを素通りさせるので、その分かれ目だけを外した写しを差し込む）。
 * 鍵の合わない要求が本物の 419 を返す（ログインが切れた・ほかの画面でログアウトしたときと同じ。新しいセッションでは画面に描いた鍵が合わない）。
 *
 * ⚠ 鍵の確かめは web グループの中で、ログインの確かめ（auth）より前に走る。保存の要求でログインが切れていると 401 より先に 419 が返る。
 * 前例: CostCardAccessErrorTest（原価のカード）・ExpiredSessionResponseTest（基幹の JSON の 419 とふつうのフォームの 419 の画面）。
 */
trait EnforcesCsrfToken
{
    protected function enforceCsrfToken(): void
    {
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
