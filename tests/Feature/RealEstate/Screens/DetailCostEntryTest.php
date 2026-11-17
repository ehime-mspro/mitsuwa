<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 仕入れ案件・分譲地の詳細の原価の入力の表で、0 円と空欄を区別して扱う（0 は金額・空は「入れない／消す／未定にする」）。
 *
 * ⚠ 昔の 1 行ずつの追加・編集（Bug #88）は、真偽値で空を判定していたので 0 が「空」になった（見込み額 0 は送られず、確定額 0 は
 *   未定で保存され、見込み額を空にすると 0 で保存された）。表になっても同じ誤りを入れないよう、4 つを固定する。
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

    private function drive(string $html, string $function, string $steps, bool $confirm = true): array
    {
        return $this->driveAlpine($html, $function, $function . '()', $steps, [], $confirm, [], ['function costExcelImporterFactory(', 'function costGridFactory(']);
    }

    /** 表の「測量費」の行に金額を打つ JS */
    private function typeSurvey(string $amount): string
    {
        return 'var r = data.costGrid.rows.filter(function (r) { return r.name === "測量費"; })[0]; r.draft.amount = ' . json_encode($amount) . ';';
    }

    #[DataProvider('screens')]
    public function test_a_zero_estimate_is_saved_as_zero(string $kind): void
    {
        [$html, $costs, , $function] = $this->screen($kind);

        $run = $this->drive($html(), $function, 'data.openCostGrid("estimated"); ' . $this->typeSurvey('0') . ' data.saveCostGrid();');

        $this->assertCount(1, $run['requests'], '見込み額 0 が送られない');
        $this->assertSame(0, json_decode((string) $run['requests'][0]['body'], true)['rows'][0]['amount']);
        $this->sendCaptured($run['requests'][0])->assertOk();
        $cost = $costs()->where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([0, null], [$cost->estimated_amount, $cost->actual_amount]);
    }

    #[DataProvider('screens')]
    public function test_a_zero_actual_is_saved_as_zero_not_undecided(string $kind): void
    {
        [$html, $costs, $make, $function] = $this->screen($kind);
        $make(500000);

        $run = $this->drive($html(), $function, 'data.openCostGrid("actual"); ' . $this->typeSurvey('0') . ' data.saveCostGrid();');

        $this->assertCount(1, $run['requests']);
        $this->assertSame(0, json_decode((string) $run['requests'][0]['body'], true)['rows'][0]['amount'], '確定額 0 が空として送られる');
        $this->sendCaptured($run['requests'][0])->assertOk();
        $this->assertSame([500000, 0], [$costs()->value('estimated_amount'), $costs()->value('actual_amount')], '確定額 0 が未定（null）になる');
    }

    #[DataProvider('screens')]
    public function test_clearing_an_estimate_deletes_the_row_instead_of_saving_zero(string $kind): void
    {
        [$html, $costs, $make, $function] = $this->screen($kind);
        $make(500000);

        $run = $this->drive($html(), $function, 'data.openCostGrid("estimated"); ' . $this->typeSurvey('') . ' data.saveCostGrid();');

        $this->assertCount(1, $run['confirms'], '行を消す確かめが出ていない');
        $this->assertCount(1, $run['requests']);
        $this->assertNull(json_decode((string) $run['requests'][0]['body'], true)['rows'][0]['amount'], '空の見込み額が 0 で送られる');
        $this->sendCaptured($run['requests'][0])->assertOk();
        $this->assertSame(0, $costs()->count(), '見込み額を空にしても行が残る（0 で保存された）');
    }

    #[DataProvider('screens')]
    public function test_clearing_an_actual_sets_undecided_instead_of_zero(string $kind): void
    {
        [$html, $costs, $make, $function] = $this->screen($kind);
        $make(500000)->update(['actual_amount' => 480000]);

        $run = $this->drive($html(), $function, 'data.openCostGrid("actual"); ' . $this->typeSurvey('') . ' data.saveCostGrid();');

        $this->assertSame([], $run['confirms'], '確定額を空にするのは行を消さないので、確かめは出ない');
        $this->assertCount(1, $run['requests']);
        $this->assertNull(json_decode((string) $run['requests'][0]['body'], true)['rows'][0]['amount']);
        $this->sendCaptured($run['requests'][0])->assertOk();
        $this->assertSame([500000, null], [$costs()->value('estimated_amount'), $costs()->value('actual_amount')], '確定額が未定にならない（0 になった）');
        $this->assertSame(1, $costs()->count());
    }
}
