<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 仕入れ案件・分譲地の詳細の原価の追加・編集で、0 円と空欄を登録画面の原価欄（_cost_section_form）と同じに扱う。
 *
 * ⚠ 直す前は、見込み額 0 で「追加」を押しても何も起きず（要求も知らせも無し）、確定額 0 は「未定」（null）で保存され、
 *   行の編集で見込み額を空にすると 0 で保存されていた（`!this.newCost.estimated_amount` などの真偽値の判定。0 は偽）。
 * ⚠ 2 画面は同じ形の JS を別々に持つので、両方を同じテストで見る。
 */
class DetailCostEntryTest extends RealEstateScreenTestCase
{
    public static function screens(): array
    {
        return [
            '仕入れ案件' => ['procurement'],
            '分譲地' => ['project'],
        ];
    }

    /** @return array{0: callable(): string, 1: callable(): \Illuminate\Database\Eloquent\Builder, 2: callable(int): mixed, 3: string} 画面の HTML・原価の行の問い合わせ・行を作る関数・画面の関数名 */
    private function screen(string $kind): array
    {
        if ($kind === 'procurement') {
            $owner = $this->procurement();
            $html = fn () => $this->htmlOf(route('realestate.procurements.show', $owner));
            $costs = fn () => ReProcurementCost::where('procurement_id', $owner->id);
            $make = fn (int $amount) => ReProcurementCost::create(['procurement_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => $amount]);

            return [$html, $costs, $make, 'procurementDetail'];
        }
        $owner = $this->project();
        $html = fn () => $this->htmlOf(route('realestate.projects.show', $owner));
        $costs = fn () => ReProjectCost::where('project_id', $owner->id);
        $make = fn (int $amount) => ReProjectCost::create(['project_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => $amount]);

        return [$html, $costs, $make, 'projectDetail'];
    }

    private function drive(string $html, string $function, string $steps): array
    {
        return $this->driveAlpine($html, $function, $function . '()', $steps, [], true, [], ['function costExcelImporterFactory(']);
    }

    #[DataProvider('screens')]
    public function test_zero_amounts_are_added_as_zero(string $kind): void
    {
        [$html, $costs, , $function] = $this->screen($kind);
        $run = $this->drive($html(), $function, 'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: 0, actual_amount: 0, notes: "" }; data.addCost();');

        $this->assertCount(1, $run['requests'], '見込み額 0 で「追加」を押しても送られない');
        $this->sendCaptured($run['requests'][0])->assertOk();

        $cost = $costs()->where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([0, 0], [$cost->estimated_amount, $cost->actual_amount], '0 円が 0 として保存されていない（確定額 0 が未定になる）');
    }

    #[DataProvider('screens')]
    public function test_an_empty_estimate_or_item_is_not_sent_and_the_reason_is_shown(string $kind): void
    {
        [$html, , , $function] = $this->screen($kind);
        $page = $html();

        $run = $this->drive($page, $function, 'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: "", actual_amount: "", notes: "" }; data.addCost();');
        $this->assertSame([[], ['見込み額を入力してください。']], [$run['requests'], $run['alerts']]);

        $run = $this->drive($page, $function, 'data.showAddCost = true; data.newCost = { cost_item_id: "", estimated_amount: 1000, actual_amount: "", notes: "" }; data.addCost();');
        $this->assertSame([[], ['費用項目を選択してください。']], [$run['requests'], $run['alerts']]);
    }

    #[DataProvider('screens')]
    public function test_clearing_the_estimate_when_editing_is_not_saved_as_zero(string $kind): void
    {
        [$html, , $make, $function] = $this->screen($kind);
        $make(500000);

        $run = $this->drive($html(), $function, 'var c = data.costs[0]; data.startEditCost(c); data.editCost.estimated_amount = ""; data.saveCost(c);');

        $this->assertSame([[], ['見込み額を入力してください。']], [$run['requests'], $run['alerts']], '見込み額を空にすると 0 で保存される');
    }

    #[DataProvider('screens')]
    public function test_an_actual_amount_of_zero_is_saved_when_editing(string $kind): void
    {
        [$html, $costs, $make, $function] = $this->screen($kind);
        $make(500000);

        $run = $this->drive($html(), $function, 'var c = data.costs[0]; data.startEditCost(c); data.editCost.actual_amount = 0; data.saveCost(c);');
        $this->sendCaptured($run['requests'][0])->assertOk();

        $this->assertSame([500000, 0], [$costs()->value('estimated_amount'), $costs()->value('actual_amount')]);
    }
}
