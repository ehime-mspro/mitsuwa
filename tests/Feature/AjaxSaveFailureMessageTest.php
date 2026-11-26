<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\TestCase;

/**
 * 断られた保存の Ajax の文を作る部品（_partials/_ajax_save_failure の ajaxSaveFailureMessage）の決まり
 * （設計メモ 2026-11-26-save-ajax-403-design.md）。
 *
 *   1. 419・401 で本文の message が空でない文字 → その message だけ（Bug #137 のまま）
 *   2. 403 で message に日本語（ASCII 以外の文字）がある → failed ＋ その message
 *   3. ほか → fallback（呼ぶ側の今の番号つきの文）
 *
 * 部品を描いた HTML の script を node で読み、関数をそのまま呼ぶ。各画面での呼び方は画面ごとのテストが見る。
 */
class AjaxSaveFailureMessageTest extends TestCase
{
    use DrivesAlpineFetch;

    private const FAILED = 'ランクを変更できませんでした。';

    private const FALLBACK = 'ランクを変更できませんでした（N）';

    public static function cases(): array
    {
        $expired = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした。画面を開き直して、もう一度やり直してください。';
        $signedOut = 'ログインが切れました。ログインし直してから、もう一度やり直してください。';

        return [
            '419 の日本語の理由' => [419, ['message' => $expired], $expired],
            '401 の日本語の理由' => [401, ['message' => $signedOut], $signedOut],
            '401 の英語の文（今と同じくそのまま）' => [401, ['message' => 'Unauthenticated.'], 'Unauthenticated.'],
            '419 で本文なし' => [419, null, self::FALLBACK],
            '401 で空の文' => [401, ['message' => ''], self::FALLBACK],
            '403 の役割の理由' => [403, ['message' => 'このページへのアクセス権限がありません。'], self::FAILED . 'このページへのアクセス権限がありません。'],
            '403 の部署の理由' => [403, ['message' => 'この部門のデータへのアクセス権限がありません。'], self::FAILED . 'この部門のデータへのアクセス権限がありません。'],
            '403 の決裁のみの理由' => [403, ['message' => '決裁以外の画面は使えません。'], self::FAILED . '決裁以外の画面は使えません。'],
            '403 の英語の文' => [403, ['message' => 'This action is unauthorized.'], self::FALLBACK],
            '403 で空の文' => [403, ['message' => ''], self::FALLBACK],
            '403 で本文なし' => [403, null, self::FALLBACK],
            '404' => [404, ['message' => 'No query results'], self::FALLBACK],
            '422' => [422, ['message' => '入力内容に誤りがあります。'], self::FALLBACK],
            '500' => [500, ['message' => 'Server Error'], self::FALLBACK],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_message_follows_the_three_rules(int $status, ?array $body, string $expected): void
    {
        $this->assertSame([$expected], $this->evaluate([$this->expression($status, $body, self::FALLBACK)]));
    }

    /** fallback に null を渡せば、決まり 1・2 に当たらないときは null（呼ぶ側が「出さない」を選べる） */
    public function test_a_null_fallback_comes_back_as_null(): void
    {
        $this->assertSame([null], $this->evaluate([$this->expression(404, ['message' => 'No query results'], null)]));
    }

    private function expression(int $status, ?array $body, ?string $fallback): string
    {
        return 'ajaxSaveFailureMessage(' . $status . ', ' . json_encode($body, JSON_UNESCAPED_UNICODE) . ', '
            . json_encode(self::FAILED, JSON_UNESCAPED_UNICODE) . ', ' . json_encode($fallback, JSON_UNESCAPED_UNICODE) . ')';
    }

    /** @param  array<int, string>  $expressions */
    private function evaluate(array $expressions): array
    {
        $html = view('_partials._ajax_save_failure')->render();

        return $this->driveAlpine($html, null, '({})', '', [], true, $expressions, ['function ajaxSaveFailureMessage('])['evaluated'];
    }
}
