<?php

namespace Tests\Feature\Mansion\Screens;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 賃貸マンションの画面の GET の Ajax（空き部屋・空き駐車場・住所→〒）が、ログインが切れたときに理由を出す
 * （設計メモ 2026-11-17-get-ajax-failure-design.md）。
 *
 * ⚠ 応答は画面の JS が組んだ要求を本物のサーバーへ送って返ってきたもの（ログインの無い 401）。
 */
class GetAjaxFailureTest extends MansionScreenTestCase
{
    private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';

    private const TAIL = 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';

    public static function screens(): array
    {
        return [
            // 空き部屋と空き駐車場を 2 本並べて取りに行き、どちらかが断られたら 1 つだけ出す
            '部屋契約の登録' => ['mansion.contracts.create', 'contractForm', 'loadVacancies', 2, '空き部屋・空き駐車場を取得'],
            '駐車場契約の登録' => ['mansion.parking-contracts.create', 'parkingContractForm', 'loadVacantParkings', 1, '空き駐車場を取得'],
        ];
    }

    #[DataProvider('screens')]
    public function test_choosing_a_property_says_why_when_the_login_expired(string $route, string $function, string $method, int $requests, string $what): void
    {
        $html = $this->htmlOf(route($route));
        $steps = 'data.propertyId = "' . $this->building->id . '"; data.' . $method . '();';
        $sent = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps);
        $this->assertCount($requests, $sent['requests'], '物件を選んでも取りに行かない');

        auth()->logout();
        $responses = array_map(fn (array $request) => $this->asFetchResponse($this->sendCaptured($request)->assertStatus(401)), $sent['requests']);

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps, $responses, true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame([self::EXPIRED . $what . self::TAIL], $run['alerts']);
    }

    // ============================================================
    // 物件の住所→〒（XMLHttpRequest）
    // ============================================================

    private function reverseLookup(array $responses): array
    {
        $html = $this->htmlOf(route('mansion.properties.create'));

        return $this->driveAlpine($html, 'reverseMansionLookup', '({})', 'reverseMansionLookup();', $responses, true, [], ['function ajaxGetFailureMessage('],
            ['input[name="address"]' => '愛媛県松山市二番町']);
    }

    public function test_reverse_zip_says_why_when_the_login_expired(): void
    {
        $sent = $this->reverseLookup([]);
        $this->assertCount(1, $sent['requests'], '住所→〒で取りに行かない');

        auth()->logout();
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus(401));

        $this->assertSame([self::EXPIRED . '郵便番号を取得' . self::TAIL], $this->reverseLookup([$response])['alerts']);
    }

    public function test_reverse_zip_keeps_the_old_message_for_other_failures(): void
    {
        $this->assertSame(['該当なし'], $this->reverseLookup([['status' => 404, 'body' => ['error' => '該当なし']]])['alerts']);
        $this->assertSame(['該当する郵便番号が見つかりませんでした'], $this->reverseLookup([['status' => 500, 'body' => '<html>']])['alerts']);
    }
}
