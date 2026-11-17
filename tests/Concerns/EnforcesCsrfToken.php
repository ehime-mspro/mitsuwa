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
    /** 鍵が合わない JSON の要求に、サーバーが返す文（bootstrap/app.php。決裁と基幹で同じ） */
    protected const EXPIRED_JSON_MESSAGE = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした。画面を開き直して、もう一度やり直してください。';

    /**
     * 画面の JS が組んだ要求（DrivesAlpineFetch の requests の 1 件）の鍵を、前のセッションのものに差し替える。
     * 鍵はヘッダー（X-CSRF-TOKEN）か本文の `_token` に載る（ValidateCsrfToken は本文を先に見る）ので、両方を差し替える。
     *
     * @param  array{url: string, method: string, headers: array<string, string>, body: ?string}  $request
     * @return array{url: string, method: string, headers: array<string, string>, body: ?string}
     */
    protected function withExpiredToken(array $request): array
    {
        $old = 'token-of-the-old-session';
        foreach (array_keys($request['headers']) as $name) {
            if (strtolower($name) === 'x-csrf-token') {
                $request['headers'][$name] = $old;
            }
        }
        if (is_string($request['body'])) {
            $request['body'] = preg_replace('/(^|&)_token=[^&]*/', '$1_token=' . $old, $request['body']);
        }

        return $request;
    }

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
