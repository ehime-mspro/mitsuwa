<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;

/**
 * 不動産の契約の買主は、画面の選択肢と同じ集合だけを受け付ける（docs/RULES.md Bug #149 の追記・2026-11-26）。
 *
 * 画面の選択肢は「削除していない買主（部署で絞らない）＋今の買主（削除済みでも残す）」。保存の入力チェックが
 * `exists:buyers,id` だったので、手で番号を書き換えた送信なら、今の買主でない削除済みの買主も紐付けられた
 * （`exists` は論理削除を見ない。紐付くと ReContract の保存のフックがその人を不動産の部署で「成約」にする）。
 *
 * ⚠ 選択肢の範囲（部署で絞らない）は変えない。住宅事業だけの買主も今どおり選べる・保存できる（守りのテスト）。
 * ⚠ 送る値は描いた画面のフォームを分解して作り、buyer_id だけを書き換える（Bug #47）。
 */
class ContractBuyerChoiceTest extends RealEstateScreenTestCase
{
    private const REFUSED = '選択された買主は正しくありません。';

    private function deletedBuyer(): Buyer
    {
        $buyer = $this->buyer('削除', '済子');
        $buyer->delete();

        return $buyer;
    }

    /** 住宅事業だけにいる買主（不動産の部署の行が無い） */
    private function housingOnlyBuyer(): Buyer
    {
        $buyer = Buyer::create(['last_name' => '住宅', 'first_name' => '花子', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $buyer->addToDepartment('housing', '2026-09-01');

        return $buyer;
    }

    /** 不動産の部署のランク（論理削除済みの買主も読む） */
    private function realEstateRank(Buyer $buyer): ?string
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)->where('department', 'realestate')->value('rank');
    }

    /**
     * 画面の買主の選択欄（buyer_id）の選択肢。値 => [文字, 選ばれているか]（先頭の空の選択肢は除く）
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function buyerOptions(string $html): array
    {
        $this->assertSame(1, preg_match('/<select name="buyer_id"[^>]*>(.*?)<\/select>/s', $html, $select), '画面に買主の選択欄が無い');
        preg_match_all('/<option value="(\d+)"([^>]*)>([^<]*)<\/option>/', $select[1], $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $m) {
            $options[$m[1]] = [trim($m[3]), str_contains($m[2], 'selected')];
        }

        return $options;
    }

    // ============================================================
    // 登録
    // ============================================================

    /** 登録画面で仕入れ系（中古戸建）の契約を選び、仕入れ案件を選んで、日付と金額を入れる（買主はまだ選ばない） */
    private function procurementCreateForm(ReProcurement $procurement): array
    {
        $form = $this->browserForm($this->htmlOf(route('realestate.contracts.create')), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "procurement_house"; data.onTypeChange(); data.procurementId = "' . $procurement->id . '"; data.onProcurementChange();',
            [$this->apiResponse(route('api.realestate.procurement-cost', $procurement))]);

        return $this->fill($form, ['contract_date' => '2026-10-01', 'contract_amount_land' => '10000000', 'cost_amount' => '9000000']);
    }

    /** 登録画面で分譲地の契約を選び、分譲地と区画を選ぶ（ContractScreensTest と同じ。区画の一覧と原価は JS が API から取る） */
    private function subdivisionCreateForm(ReProject $project, ReProjectLot $lot): array
    {
        $responses = [
            $this->apiResponse(route('api.realestate.project-lots', $project)),
            $this->apiResponse(route('api.realestate.project-lot-cost', $project)),
        ];
        $form = $this->browserForm($this->htmlOf(route('realestate.contracts.create')), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "subdivision_lot"; data.onTypeChange(); data.projectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.lotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);

        return $this->fill($form, ['contract_date' => '2026-10-01']);
    }

    public function test_the_procurement_contract_registration_refuses_a_deleted_buyer(): void
    {
        $procurement = $this->procurement();
        $deleted = $this->deletedBuyer();
        $form = $this->fill($this->procurementCreateForm($procurement), ['buyer_id' => (string) $deleted->id]);

        $html = $this->landed($this->submit($form, route('realestate.contracts.create')));

        $this->assertErrorItem($html, self::REFUSED);
        $this->assertSame(0, ReContract::count(), '選択肢に無い削除済みの買主で契約ができた');
        $this->assertSame('C', $this->realEstateRank($deleted), '削除済みの買主が成約になった');
    }

    public function test_the_subdivision_contract_registration_refuses_a_deleted_buyer(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000);
        $deleted = $this->deletedBuyer();
        $form = $this->fill($this->subdivisionCreateForm($project, $lot), ['buyer_id' => (string) $deleted->id]);

        $html = $this->landed($this->submit($form, route('realestate.contracts.create')));

        $this->assertErrorItem($html, self::REFUSED);
        $this->assertSame(0, ReContract::count(), '選択肢に無い削除済みの買主で契約ができた');
        $this->assertSame('on_sale', $lot->fresh()->status->value, '断ったのに区画が成約になった');
        $this->assertSame('C', $this->realEstateRank($deleted), '削除済みの買主が成約になった');
    }

    /** 選択肢は削除していない買主（部署で絞らない＝住宅事業だけの人も出す）。削除済みは出さない */
    public function test_the_registration_offers_every_buyer_but_the_deleted_ones(): void
    {
        $realEstate = $this->buyer();
        $housing = $this->housingOnlyBuyer();
        $deleted = $this->deletedBuyer();

        // ⚠ 数字の文字のキーは PHP の配列で整数になるので、文字にそろえて比べる
        $values = array_map('strval', array_keys($this->buyerOptions($this->htmlOf(route('realestate.contracts.create')))));

        $this->assertSame([(string) $realEstate->id, (string) $housing->id], $values, '削除していない買主が選択肢に出ていない（か、削除済みの買主が出ている）');
        $this->assertNotContains((string) $deleted->id, $values, '削除済みの買主が選択肢に出ている');
    }

    /** 範囲を変えていないことの守り: 住宅事業だけの買主も今どおり登録できる */
    public function test_the_registration_still_accepts_a_buyer_of_another_department(): void
    {
        $procurement = $this->procurement();
        $housing = $this->housingOnlyBuyer();
        $form = $this->fill($this->procurementCreateForm($procurement), ['buyer_id' => (string) $housing->id]);

        $html = $this->landed($this->submit($form, route('realestate.contracts.create')));

        $this->assertFlash($html, 'success', '契約を登録しました。');
        $this->assertSame($housing->id, ReContract::firstOrFail()->buyer_id);
    }

    // ============================================================
    // 編集
    // ============================================================

    private function procurementContract(Buyer $buyer): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の家', 'procurement_id' => $this->procurement()->id, 'buyer_id' => $buyer->id,
            'contract_amount_land' => 10000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
    }

    private function editForm(ReContract $contract): array
    {
        return $this->browserForm($this->htmlOf(route('realestate.contracts.edit', $contract)),
            'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm', '');
    }

    public function test_the_edit_refuses_a_deleted_buyer_who_is_not_the_current_one(): void
    {
        $current = $this->buyer();
        $contract = $this->procurementContract($current);
        $deleted = $this->deletedBuyer();
        $form = $this->fill($this->editForm($contract), ['buyer_id' => (string) $deleted->id]);

        $html = $this->landed($this->submit($form, route('realestate.contracts.edit', $contract)));

        $this->assertErrorItem($html, self::REFUSED);
        $this->assertSame($current->id, $contract->fresh()->buyer_id, '選択肢に無い削除済みの買主が紐付いた');
        $this->assertSame('C', $this->realEstateRank($deleted), '削除済みの買主が成約になった');
    }

    /** 今の買主があとで削除されても、画面は「（削除済み）」で選んだまま開き、そのまま保存できる（Bug #58・#113 の形） */
    public function test_the_edit_keeps_a_deleted_current_buyer(): void
    {
        $current = $this->buyer('今', '一郎');
        $contract = $this->procurementContract($current);
        $current->delete();
        $url = route('realestate.contracts.edit', $contract);

        $this->assertSame(['今 一郎（削除済み）', true], $this->buyerOptions($this->htmlOf($url))[(string) $current->id] ?? null,
            '今の買主が「（削除済み）」で選ばれていない');
        $form = $this->editForm($contract);
        $this->assertSame((string) $current->id, $form['fields']['buyer_id'], '今の買主が送られない');
        $form = $this->fill($form, ['memo' => '駐車場の件']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame([$current->id, '駐車場の件'], [$contract->fresh()->buyer_id, $contract->fresh()->memo]);
    }
}
