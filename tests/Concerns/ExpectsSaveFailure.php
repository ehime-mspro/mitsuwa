<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;

/**
 * 保存の途中で落ちたとき、差し込んだ例外がそのまま投げられる（画面は今どおり 500）ことを確かめる
 * （区画のステータスを動かす保存のトランザクションのテストで共用。docs/RULES.md Bug #148）。
 *
 * 例外は FailsImportWrites::failQuery() で、区画・集約の SQL を実行する前に断って起こす。
 * 投げられたのが差し込んだ例外かを見る（ほかの理由で落ちたのを「巻き戻った」と読み違えないため）。
 */
trait ExpectsSaveFailure
{
    /** $send（画面が描いたフォームを送る処理）を実行し、$exception が投げられることを確かめる */
    private function assertSaveFails(callable $send, string $exception = QueryException::class): void
    {
        $this->withoutExceptionHandling();
        try {
            $send();
            $this->fail('保存の途中の例外が投げられていない');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }
}
