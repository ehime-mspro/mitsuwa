<?php

namespace Tests\Feature;

use Tests\Concerns\DrivesAlpineFetch;
use Tests\TestCase;

/**
 * DrivesAlpineFetch の偽の fetch の json() が、本物のブラウザと同じく本文を読むこと（2026-11-18・保存の Ajax の 401 の後回し）。
 *
 * 本物のブラウザの Response.json() は本文を JSON として読み、読めなければ失敗する（HTML で返った 401・419 など）。
 * 直す前の偽物は本文を読まずにそのまま返していたので、画面の `r.json().catch(function () { return null; })` を
 * 消してもテストが緑のままだった（失敗が起きないので、受け止める所が要らない形に見える）。
 *
 * 応答の body: 文字なら本文の生の文字（JSON として読み、読めなければ失敗。偽の XMLHttpRequest の responseText と同じ扱い）。
 * 文字でないもの（配列・null。asFetchResponse() がサーバーの JSON を読んだ値）はそのまま返す（今までどおり）。
 */
class FakeFetchJsonTest extends TestCase
{
    use DrivesAlpineFetch;

    /** fetch して json() の結果を out に入れる小さな部品（インラインの x-data と同じく、画面の script は読まない） */
    private const PART = '({ out: null, run: function () { var self = this; fetch("/probe", { method: "POST" })'
        . '.then(function (r) { return r.json(); })'
        . '.then(function (body) { self.out = ["resolved", body]; }, function (e) { self.out = ["rejected", e && e.name]; }); } })';

    /** @return array{0: string, 1: mixed} [resolved か rejected, 読んだ値か失敗の名前] */
    private function jsonOf(mixed $body): array
    {
        return $this->driveAlpine('', null, self::PART, 'data.run();', [['status' => 401, 'body' => $body]], true, ['out'])['evaluated'][0];
    }

    /** 本文が JSON でない（HTML・空）と json() は失敗する（ブラウザと同じ SyntaxError） */
    public function test_a_body_that_is_not_json_makes_json_fail(): void
    {
        $this->assertSame(['rejected', 'SyntaxError'], $this->jsonOf('<!DOCTYPE html><title>x</title>'));
        $this->assertSame(['rejected', 'SyntaxError'], $this->jsonOf(''));
    }

    /** 本文が JSON の文字なら読んだ値を返す */
    public function test_a_body_that_is_json_text_is_read(): void
    {
        $this->assertSame(['resolved', ['message' => '理由']], $this->jsonOf('{"message":"理由"}'));
    }

    /** 読んだあとの値（配列・null）はそのまま返す（今までどおり） */
    public function test_a_decoded_body_is_returned_as_it_is(): void
    {
        $this->assertSame(['resolved', ['message' => '理由']], $this->jsonOf(['message' => '理由']));
        $this->assertSame(['resolved', null], $this->jsonOf(null));
    }

    /** htmlResponse() は JSON として読めない本文を返す */
    public function test_the_html_response_is_not_json(): void
    {
        $response = $this->htmlResponse(401);

        $this->assertSame(401, $response['status']);
        $this->assertSame(['rejected', 'SyntaxError'], $this->jsonOf($response['body']));
    }
}
