<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 不動産の画面の GET の Ajax（契約の登録・編集で仕入れ案件・分譲地を選んだときの原価と区画・仕入れ先の検索）が、
 * ログインが切れたときに理由を出す（設計メモ 2026-11-17-get-ajax-failure-design.md）。
 * 今まで「データの取得に失敗しました（N）」と出ていた所は、何を取りに行っていたかで言う。
 *
 * ⚠ 応答は画面の JS が組んだ要求を本物のサーバーへ送って返ってきたもの（ログインの無い 401）。
 */
class GetAjaxFailureTest extends RealEstateScreenTestCase
{
    private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';

    private const TAIL = 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';

    /** @return array{0: string, 1: string, 2: string} [画面の URL, 関数, 選ぶ手順] */
    private function contractScreen(string $screen, string $source): array
    {
        if ($source === 'procurement') {
            $procurement = $this->procurement(['property_type' => 'used_house']);
            $choose = $screen === 'create'
                ? 'data.contractType = "procurement_house"; data.onTypeChange(); data.procurementId = "' . $procurement->id . '"; data.onProcurementChange();'
                : 'data.procurementId = "' . $procurement->id . '"; data.onProcurementChange();';
        } else {
            $project = $this->project();
            $choose = $screen === 'create'
                ? 'data.contractType = "subdivision_lot"; data.onTypeChange(); data.projectId = "' . $project->id . '"; data.onProjectChange();'
                : 'data.projectId = "' . $project->id . '"; data.onProjectChange();';
        }
        if ($screen === 'create') {
            return [route('realestate.contracts.create'), 'contractForm', $choose];
        }
        $contract = $source === 'procurement'
            ? ReContract::create([
                'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
                'property_name' => '勝山の家', 'procurement_id' => $this->procurement(['procurement_code' => 'RE-PRC-099'])->id, 'buyer_id' => $this->buyer()->id,
                'contract_amount_land' => 10000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
            ])
            : ReContract::create([
                'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted', 'contract_date' => '2026-09-01',
                'property_name' => '平井分譲地', 'project_id' => $this->project(['project_code' => 'RE-PRJ-099', 'project_name' => '北条分譲地'])->id,
                'buyer_id' => $this->buyer()->id, 'contract_amount_land' => 12000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
            ]);

        return [route('realestate.contracts.edit', $contract), 'contractEditForm', $choose];
    }

    public static function contractChoices(): array
    {
        return [
            '登録・仕入れ案件' => ['create', 'procurement', ['仕入れ案件の原価を取得']],
            '登録・分譲地' => ['create', 'project', ['分譲地の区画を取得', '分譲地の原価を取得']],
            '編集・仕入れ案件' => ['edit', 'procurement', ['仕入れ案件の原価を取得']],
            '編集・分譲地' => ['edit', 'project', ['分譲地の区画を取得', '分譲地の原価を取得']],
        ];
    }

    #[DataProvider('contractChoices')]
    public function test_choosing_on_the_contract_screen_says_why_when_the_login_expired(string $screen, string $source, array $whats): void
    {
        [$url, $function, $choose] = $this->contractScreen($screen, $source);
        $html = $this->htmlOf($url);
        $sent = $this->driveAlpine($html, $function, $this->xData($html, $function), $choose);
        $this->assertCount(count($whats), $sent['requests'], '選んでも取りに行かない');

        auth()->logout();
        $responses = array_map(fn (array $request) => $this->asFetchResponse($this->sendCaptured($request)->assertStatus(401)), $sent['requests']);

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $choose, $responses, true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame(array_map(fn (string $what) => self::EXPIRED . $what . self::TAIL, $whats), $run['alerts']);
    }

    public function test_other_failures_name_what_was_being_fetched(): void
    {
        [$url, $function, $choose] = $this->contractScreen('create', 'procurement');
        $html = $this->htmlOf($url);

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $choose, [['status' => 500, 'body' => null]], true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame(['仕入れ案件の原価の取得に失敗しました（500）'], $run['alerts']);
    }

    public function test_the_supplier_search_says_why_when_the_login_expired(): void
    {
        $html = $this->htmlOf(route('realestate.procurements.create'));
        // 検索は打鍵の待ち（setTimeout）のあとに送るので、待たずにすぐ呼ぶ
        $search = "setTimeout = function (fn) { fn(); return 0; }; data.supplierQuery = '松山'; data.searchSupplier();";
        $sent = $this->driveAlpine($html, 'supplierPicker', 'supplierPicker()', $search);
        $this->assertCount(1, $sent['requests'], '仕入れ先を検索しても取りに行かない');

        auth()->logout();
        $response = $this->asFetchResponse($this->sendCaptured($sent['requests'][0])->assertStatus(401));

        $run = $this->driveAlpine($html, 'supplierPicker', 'supplierPicker()', $search, [$response], true, [], ['function ajaxGetFailureMessage(']);

        $this->assertSame([self::EXPIRED . '仕入れ先を検索' . self::TAIL], $run['alerts']);
    }
}
