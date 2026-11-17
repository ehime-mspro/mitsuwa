<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 住宅事業の画面の GET の Ajax（区画・仕入れ案件の情報・顧客の検索）と、買主の住所→〒（住宅事業・不動産で共用）が、
 * ログインが切れた・権限が無いときに理由を出す（設計メモ 2026-11-17-get-ajax-failure-design.md）。
 *
 * ⚠ 応答は画面の JS が組んだ要求を本物のサーバーへ送って返ってきたもの（ログインの無い 401・部署の無い 403）。
 */
class GetAjaxFailureTest extends HousingScreenTestCase
{
    private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';

    private const TAIL = 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';

    private const NO_DEPARTMENT = 'この部門のデータへのアクセス権限がありません。';

    public static function choices(): array
    {
        return [
            '建売・区画' => ['housing.properties.create', 'housingPropertyForm', 'lot', '区画を取得'],
            '建売・仕入れ案件' => ['housing.properties.create', 'housingPropertyForm', 'procurement', '仕入れ案件の情報を取得'],
            '注文住宅・区画' => ['housing.custom-orders.create', 'customOrderForm', 'lot', '区画を取得'],
            '注文住宅・仕入れ案件' => ['housing.custom-orders.create', 'customOrderForm', 'procurement', '仕入れ案件の情報を取得'],
        ];
    }

    #[DataProvider('choices')]
    public function test_choosing_a_lot_or_procurement_says_why_when_the_login_expired(string $route, string $function, string $source, string $what): void
    {
        $html = $this->htmlOf(route($route));
        $steps = $source === 'lot'
            ? 'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $this->project()->id . '"; data.onProjectChange();'
            : 'data.landSourceType = "procurement"; data.onSourceTypeChange(); data.selectedProcurementId = "' . $this->procurement()->id . '"; data.onProcurementChange();';
        $sent = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps);
        $this->assertCount(1, $sent['requests'], '選んでも取りに行かない');

        auth()->logout();
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus(401));

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps, [$response], true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame([self::EXPIRED . $what . self::TAIL], $run['alerts']);
    }

    public static function customerSearchRefusals(): array
    {
        return [
            'ログインが無い（401）' => ['signedOut', 401, self::EXPIRED . '顧客を検索' . self::TAIL],
            // 注文住宅の顧客名はテナントの顧客を検索する（住宅事業だけの人は部署の確かめに断られる。今もある場面）
            '住宅事業だけの管理者（403）' => ['housingOnly', 403, '顧客を検索できませんでした。' . self::NO_DEPARTMENT],
        ];
    }

    #[DataProvider('customerSearchRefusals')]
    public function test_the_custom_order_customer_search_says_why(string $case, int $status, string $expected): void
    {
        if ($case === 'housingOnly') {
            $this->user = $this->member(UserRole::Manager, '住宅 管理');
        }
        $html = $this->htmlOf(route('housing.custom-orders.create'));
        // 検索は打鍵の待ち（setTimeout）のあとに送るので、待たずにすぐ呼ぶ
        $search = "setTimeout = function (fn) { fn(); return 0; }; data.customerName = '山田'; data.searchCustomer();";
        $sent = $this->driveAlpine($html, 'customOrderForm', $this->xData($html, 'customOrderForm'), $search);
        $this->assertCount(1, $sent['requests'], '顧客を検索しても取りに行かない');

        if ($case === 'signedOut') {
            auth()->logout();
        }
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus($status));

        $run = $this->driveAlpine($html, 'customOrderForm', $this->xData($html, 'customOrderForm'), $search, [$response], true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame($expected, $run['state']['customerError']);
        $this->assertSame([], $run['alerts']);
    }

    // ============================================================
    // 買主の住所→〒（XMLHttpRequest。住宅事業・不動産の顧客の登録・編集で共用）
    // ============================================================

    private function reverseLookup(array $responses): array
    {
        $html = $this->htmlOf(route('housing.customers.create'));

        return $this->driveAlpine($html, 'reverseLookup', '({})', 'reverseLookup();', $responses, true, [], ['function ajaxGetFailureMessage('],
            ['select[name="prefecture"]' => '愛媛県', 'input[name="city"]' => '松山市']);
    }

    public function test_buyer_reverse_zip_says_why_when_the_login_expired(): void
    {
        $sent = $this->reverseLookup([]);
        $this->assertCount(1, $sent['requests'], '住所→〒で取りに行かない');

        auth()->logout();
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus(401));

        $this->assertSame([self::EXPIRED . '郵便番号を取得' . self::TAIL], $this->reverseLookup([$response])['alerts']);
    }

    public function test_buyer_reverse_zip_keeps_the_old_message_for_other_failures(): void
    {
        $this->assertSame(['該当なし'], $this->reverseLookup([['status' => 404, 'body' => ['error' => '該当なし']]])['alerts']);
        // 本文が JSON でない（途中の中継の HTML など）ときも止まらずに、今の文を出す
        $this->assertSame(['該当する郵便番号が見つかりませんでした'], $this->reverseLookup([['status' => 500, 'body' => '<html>']])['alerts']);
    }
}
