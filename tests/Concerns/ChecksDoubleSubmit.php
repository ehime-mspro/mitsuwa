<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * 確定を 2 回送る測りの道具（設計書 2026-09-28-import-double-submit-design.md §5.1・§5.4）。
 *
 * 使う側は `ParsesForms`（htmlAttr() を借りる）と一緒に use する。
 *
 * ⚠ 断りは、戻り先・役割（`error` のフラッシュ）・表示（戻り先の画面の赤帯）を分けて見る。
 *   `assertSessionHas*()` は使わない（そのあと描いた画面から表示が消えることがある。Bug #49）。
 * ⚠ 2 回目で何も書かないことは、件数に加えて SQL の書き込みの数で見る（工程表の入れ替えは消してから同じ数を入れ、
 *   シート取込は同じ値のセルを飛ばして履歴だけを足すので、件数だけでは見落とす）。
 */
trait ChecksDoubleSubmit
{
    /** 数えている間の書き込みの SQL の数（null のあいだは数えない） */
    private ?int $writeCount = null;

    private bool $listeningForWrites = false;

    /**
     * $send のあいだに走った INSERT / UPDATE / DELETE の数。
     *
     * @return array{0: mixed, 1: int} [$send の戻り値, 書き込みの SQL の数]
     */
    private function countingWrites(callable $send): array
    {
        if (! $this->listeningForWrites) {
            DB::listen(function ($query): void {
                if ($this->writeCount !== null && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                    $this->writeCount++;
                }
            });
            $this->listeningForWrites = true;
        }

        $this->writeCount = 0;
        try {
            $result = $send();
        } finally {
            $count = $this->writeCount;
            $this->writeCount = null;
        }

        return [$result, $count];
    }

    /**
     * 断られたこと。⚠ 要求の直後（ほかの要求を送る前）に呼ぶ（`error` のフラッシュは次の要求で消える）。
     */
    private function assertRefused(TestResponse $response, string $location, string $message, User $user): void
    {
        $this->assertSame(
            302,
            $response->getStatusCode(),
            '断りが転送になっていない' . ($response->exception ? '（' . get_class($response->exception) . ': ' . $response->exception->getMessage() . '）' : '')
        );
        $this->assertSame($location, $response->headers->get('Location'), '断ったあとの戻り先が違う');
        // 役割: error のフラッシュに全文が入っている
        $this->assertSame($message, session('error'), '断りの文言（error のフラッシュ）が違う');

        // 表示: 戻り先を開くと、レイアウトの赤帯（layouts/app.blade.php）の中に全文が出る
        $html = $this->actingAs($user)->get($location)->getContent();
        $this->assertStringContainsString(
            '<span class="text-sm text-red-800">' . e($message) . '</span>',
            $html,
            '戻り先の画面の赤帯に、断りの文言が出ていない'
        );
    }

    /**
     * 確定のフォームが二度押し止めの部品（_partials/_submit_once）につながっていること（設計書 §4.5・§5.4）。
     * どれも**その要素に**付いていることを見る（ページのどこかに在るだけでは足りない。Bug #47）。
     *
     * @param  string  $action        確定のフォームの action（`action="…"` ごと探す。ParsesForms と同じ理由）
     * @param  string  $xData         フォームの x-data の値
     * @param  string  $statusText    送信中の文字
     * @param  string  $opacityClass  送信中の薄さのクラス（周辺ビルはいまの disabled:opacity-50 のまま）
     */
    private function assertSubmitOnceForm(
        string $html,
        string $action,
        string $xData = 'submitOnce()',
        string $statusText = '取り込んでいます…',
        string $opacityClass = 'disabled:opacity-60'
    ): void {
        $needle = 'action="' . $action . '"';
        $pos    = strpos($html, $needle);
        $this->assertNotFalse($pos, "確定のフォームが無い: {$needle}");

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);
        $this->assertNotFalse($open, "{$needle} を含む <form> の開始タグが無い");
        $this->assertNotFalse($close, "{$needle} を含む <form> が閉じていない");

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        // 部品を使う印は、確定のフォームそのものに付ける
        $this->assertSame($xData, $this->htmlAttr($openTag, 'x-data'), '確定のフォームの x-data が二度押し止めの部品でない');
        $this->assertStringContainsString('x-on:submit="onSubmit($event)"', $openTag, '確定のフォームが送信を部品に渡していない');
        // pageshow は window にしか届かない（Bug #65）
        $this->assertStringContainsString('x-on:pageshow.window="onPageShow($event)"', $openTag, '確定のフォームが pageshow を部品に渡していない');

        // 1 回限りの鍵
        $this->assertSame(
            1,
            preg_match('/<input\b[^>]*(?<![\w:.@-])name="import_token"[^>]*>/u', $form, $token),
            '確定のフォームに 1 回限りの鍵（import_token）の hidden が無い'
        );
        $this->assertSame('hidden', $this->htmlAttr($token[0], 'type'));
        $this->assertNotSame('', (string) $this->htmlAttr($token[0], 'value'), '1 回限りの鍵（import_token）の値が描かれていない');

        // 送信ボタン（1 つ）
        $this->assertSame(1, preg_match_all('/<button\b[^>]*\btype="submit"[^>]*>/u', $form, $buttons), '確定のフォームの送信ボタンが 1 つでない');
        $button = $buttons[0][0];
        // 式の先頭を submitting に固定する（`!submitting` のような逆の式を通さない。周辺ビルは `submitting || 押せない理由` の形。
        // 2026-09-28 の独立レビューで、語の有無だけを見ていた形が `:disabled="!submitting"` を通すことを実測）
        $this->assertMatchesRegularExpression('/\s:disabled="submitting(?: \|\| [^"]+)?"/', $button, '送信中にボタンを押せなくしていない');
        $classes = preg_split('/\s+/', (string) $this->htmlAttr($button, 'class'));
        foreach (['cursor-pointer', 'disabled:cursor-not-allowed', $opacityClass] as $class) {
            $this->assertContains($class, $classes, "送信ボタンに {$class} が無い");
        }
        // style の cursor はクラスより強い（送信中もカーソルが変わらない）
        $this->assertStringNotContainsString('cursor', (string) $this->htmlAttr($button, 'style'), '送信ボタンの style に cursor がある');
        // 押せなくしたボタンは送る中身から落ちる（name を付けると、その値が届かなくなる）
        $this->assertNull($this->htmlAttr($button, 'name'), '送信ボタンに name がある');

        // 送信中の文字は、フォームの中にいつも置いて、文字だけ変える
        $this->assertSame(1, preg_match('/<span\b[^>]*\brole="status"[^>]*>/u', $form, $status), '確定のフォームに role="status" が無い');
        $this->assertStringContainsString('x-text="submitting ? \'' . $statusText . '\' : \'\'"', $status[0]);
        // 375px で文字の途中で折り返さず、まとまって次の行へ移る（顧客の取込で実測）
        $this->assertStringContainsString('display: inline-block', (string) $this->htmlAttr($status[0], 'style'), '送信中の文字が inline-block でない');
    }
}
