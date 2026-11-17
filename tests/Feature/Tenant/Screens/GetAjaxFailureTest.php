<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Inquiry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET の Ajax でログインが切れた・無効にされた・権限が無いときに、番号ではなく理由を出す
 * （設計メモ 2026-11-17-get-ajax-failure-design.md。部品 _partials/_ajax_get_failure）。
 *
 * ⚠ 応答は手で作らず、画面の JS が組んだ要求を本物のサーバーへ送って返ってきたもの（401 の英語の本文・
 *   無効にされた人の日本語の 401・部署の無い人の 403）を、もう一度 JS に戻して出る文を全文で見る。
 * ⚠ GET は送信の鍵を確かめないので、ログインが切れたときは 419 ではなく 401（`Unauthenticated.`）になる。
 */
class GetAjaxFailureTest extends TenantScreenTestCase
{
    private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';

    private const TAIL = 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';

    private const INACTIVE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    private const NO_DEPARTMENT = 'この部門のデータへのアクセス権限がありません。';

    // ============================================================
    // 部品（ajaxGetFailureMessage / ajaxGetFailure）の分かれ目
    // ============================================================

    public function test_the_helper_says_why_for_each_kind_of_failure(): void
    {
        $html = $this->htmlOf(route('tenant.contracts.create'));
        $evaluate = [
            "ajaxGetFailureMessage(401, {message: 'Unauthenticated.'}, '空き区画を取得', 'F')",
            "ajaxGetFailureMessage(401, null, 'X', 'F')",
            "ajaxGetFailureMessage(401, {message: ['無効']}, 'X', 'F')",
            "ajaxGetFailureMessage(401, {message: '" . self::INACTIVE . "'}, 'X', 'F')",
            "ajaxGetFailureMessage(403, {message: '" . self::NO_DEPARTMENT . "'}, 'X', 'F')",
            "ajaxGetFailureMessage(403, {message: 'This action is unauthorized.'}, 'X', 'F')",
            "ajaxGetFailureMessage(500, {message: '日本語の理由'}, 'X', 'F')",
            "ajaxGetFailureMessage(404, null, 'X', null)",
        ];
        $steps = "ajaxGetFailure({status: 401, json: function () { return Promise.reject(new Error('HTML')); }}, 'X', 'F').then(function (m) { data.html = m; });"
            . " ajaxGetFailure({status: 403, json: function () { return Promise.resolve({message: '" . self::NO_DEPARTMENT . "'}); }}, 'X', 'F').then(function (m) { data.forbidden = m; });";

        $run = $this->driveAlpine($html, null, '({})', $steps, [], true, $evaluate, ['function ajaxGetFailureMessage(']);

        $this->assertSame([
            self::EXPIRED . '空き区画を取得' . self::TAIL,
            self::EXPIRED . 'X' . self::TAIL,
            self::EXPIRED . 'X' . self::TAIL,
            'Xできませんでした。' . self::INACTIVE,
            'Xできませんでした。' . self::NO_DEPARTMENT,
            'F',
            'F',
            null,
        ], $run['evaluated']);
        $this->assertSame(self::EXPIRED . 'X' . self::TAIL, $run['state']['html'] ?? null, '本文が JSON でない 401 でログインが切れた文にならない');
        $this->assertSame('Xできませんでした。' . self::NO_DEPARTMENT, $run['state']['forbidden'] ?? null, '403 の本文の理由を読んでいない');
    }

    // ============================================================
    // 契約の登録: 物件を選ぶと空き区画と問合せを取りに行く（GET が 2 本並ぶ）
    // ============================================================

    public static function refusals(): array
    {
        return [
            'ログインが無い（401）' => ['signedOut', 401, [
                self::EXPIRED . '空き区画を取得' . self::TAIL,
                self::EXPIRED . '問合せを取得' . self::TAIL,
            ]],
            // 1 本目で EnsureUserIsActive がログアウトさせるので、2 本目はログインの無い 401（英語の本文）になる
            '無効にされた（401）' => ['inactive', 401, [
                '空き区画を取得できませんでした。' . self::INACTIVE,
                self::EXPIRED . '問合せを取得' . self::TAIL,
            ]],
            '部署が無い（403）' => ['forbidden', 403, [
                '空き区画を取得できませんでした。' . self::NO_DEPARTMENT,
                '問合せを取得できませんでした。' . self::NO_DEPARTMENT,
            ]],
        ];
    }

    #[DataProvider('refusals')]
    public function test_choosing_a_property_says_why_when_refused(string $case, int $status, array $expected): void
    {
        $html = $this->htmlOf(route('tenant.contracts.create'));
        $choose = "data.propertyId = '{$this->building->id}'; data.onPropertyChange();";
        $sent = $this->driveAlpine($html, 'contractCreateForm', $this->xData($html, 'contractCreateForm'), $choose);
        $this->assertCount(2, $sent['requests'], '物件を選んでも空き区画と問合せを取りに行かない');

        match ($case) {
            'signedOut' => auth()->logout(),
            'inactive' => $this->user->forceFill(['status' => UserStatus::Inactive->value])->save(),
            // 経営層は部署の確かめを通るので、部署を持たない管理者にする
            'forbidden' => $this->user->forceFill(['role' => UserRole::Manager->value])->save(),
        };
        $responses = array_map(fn (array $request) => $this->asFetchResponse($this->sendCaptured($request)->assertStatus($status)), $sent['requests']);

        $run = $this->driveAlpine($html, 'contractCreateForm', $this->xData($html, 'contractCreateForm'), $choose, $responses, true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame($expected, $run['alerts']);
    }

    public function test_other_failures_keep_the_old_message(): void
    {
        $html = $this->htmlOf(route('tenant.contracts.create'));
        $choose = "data.propertyId = '999999'; data.onPropertyChange();";
        $sent = $this->driveAlpine($html, 'contractCreateForm', $this->xData($html, 'contractCreateForm'), $choose);
        $responses = array_map(fn (array $request) => $this->asFetchResponse($this->actingAs($this->user)->sendCaptured($request)->assertNotFound()), $sent['requests']);

        $run = $this->driveAlpine($html, 'contractCreateForm', $this->xData($html, 'contractCreateForm'), $choose, $responses, true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame(['空き区画の取得に失敗しました（404）', '問合せの取得に失敗しました（404）'], $run['alerts']);
    }

    // ============================================================
    // 顧客の検索（欄の下に出す。4 画面）
    // ============================================================

    public static function customerSearches(): array
    {
        return [
            '契約の登録' => ['contractCreate', 'contractCreateForm'],
            '契約の編集' => ['contractEdit', 'contractEditForm'],
            '問合せの登録' => ['inquiryCreate', 'inquiryCreateForm'],
            '問合せの編集' => ['inquiryEdit', 'inquiryEditForm'],
        ];
    }

    #[DataProvider('customerSearches')]
    public function test_a_customer_search_says_why_under_the_field(string $screen, string $function): void
    {
        $url = match ($screen) {
            'contractCreate' => route('tenant.contracts.create'),
            'contractEdit' => route('tenant.contracts.edit', $this->activeContract($this->unit(1, 'A'))),
            'inquiryCreate' => route('tenant.inquiries.create'),
            'inquiryEdit' => route('tenant.inquiries.edit', Inquiry::create([
                'inquiry_number' => 'INQ-2026-050', 'property_id' => $this->building->id, 'contact_name' => '問合 花子',
                'inquiry_date' => '2026-09-01', 'status' => InquiryStatus::Follow->value,
            ])),
        };
        $html = $this->htmlOf($url);
        // 検索は打鍵の待ち（setTimeout）のあとに送るので、待たずにすぐ呼ぶ
        $search = "setTimeout = function (fn) { fn(); return 0; }; data.customerQuery = '山田'; data.searchCustomers();";
        $sent = $this->driveAlpine($html, $function, $this->xData($html, $function), $search);
        $this->assertCount(1, $sent['requests'], '顧客を検索しても取りに行かない');

        auth()->logout();
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus(401));

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $search, [$response], true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame(self::EXPIRED . '顧客を検索' . self::TAIL, $run['state']['customerError']);
        $this->assertTrue($run['state']['showCustomerDropdown'], '理由を出す欄（候補の箱）が開かない');
        $this->assertSame([], $run['alerts']);
    }
}
