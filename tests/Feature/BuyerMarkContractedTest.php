<?php

namespace Tests\Feature;

use App\Enums\BuyerRank;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * Buyer::markContracted() の規則そのものを固定する（設計書 §4）。
 *
 * ここは「規則が正しいか」だけを見る。**その規則に実際に到達するか**は入口ごとに別テストで測る
 * （ReContractBuyerRankTest / HousingContractBuyerRankTest）。片方だけでは足りない — Bug #44。
 */
class BuyerMarkContractedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function makeBuyer(string $lastName = '山田'): Buyer
    {
        return Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);
    }

    private function pivot(Buyer $buyer, string $department): ?BuyerDepartmentPivot
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)
            ->where('department', $department)
            ->first();
    }

    /** 決定1: 対象部署の行が無い顧客は、その部署を成約ランクで自動追加する(取得日＝渡した日付)。 */
    public function test_missing_department_row_is_created_as_contracted_with_given_date(): void
    {
        $buyer = $this->makeBuyer();

        $buyer->markContracted('realestate', '2026-07-19');

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertNotNull($pivot, '部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString());
    }

    /**
     * 取得日を渡さない場合は当日日付を使う(acquired_date は NOT NULL)。
     *
     * ⚠ 凍結日は「実際の今日」と違う日にすること。今日と同じ日付を選ぶと、
     *    フォールバックが壊れていても緑になり、テストが何も証明しない。
     *
     * ⚠ travelTo() の引数は DateTimeInterface 型なので文字列は渡せない(TypeError になる)。
     */
    public function test_missing_date_falls_back_to_today(): void
    {
        $this->travelTo(Carbon::parse('2030-03-15'));
        $buyer = $this->makeBuyer();

        $buyer->markContracted('realestate', null);

        $this->assertSame('2030-03-15', $this->pivot($buyer, 'realestate')->acquired_date->toDateString());
    }

    /**
     * 既存行は rank だけ上書きし、acquired_date は書き換えない。
     *
     * ⚠ 取得日は「いつ獲得した顧客か」という独立した実データで、契約日で潰すと
     *    獲得経路の履歴が失われる(設計書 §4)。
     */
    public function test_existing_row_keeps_its_acquired_date(): void
    {
        $buyer = $this->makeBuyer();
        BuyerDepartmentPivot::create([
            'buyer_id'      => $buyer->id,
            'department'    => 'realestate',
            'acquired_date' => '2026-01-05',
            'rank'          => BuyerRank::B->value,
        ]);

        $buyer->markContracted('realestate', '2026-07-19');

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-01-05', $pivot->acquired_date->toDateString(), '取得日が契約日で上書きされている');
    }

    /** 他決・追客不可も無条件で成約に上書きする(契約したという事実が最も強い。設計書 §4)。 */
    public function test_lost_and_unreachable_ranks_are_overwritten(): void
    {
        foreach ([BuyerRank::Lost, BuyerRank::Unreachable] as $i => $rank) {
            $buyer = $this->makeBuyer('上書き' . $i);
            BuyerDepartmentPivot::create([
                'buyer_id'      => $buyer->id,
                'department'    => 'housing',
                'acquired_date' => '2026-01-05',
                'rank'          => $rank->value,
            ]);

            $buyer->markContracted('housing', '2026-07-19');

            $this->assertSame(
                BuyerRank::Contracted,
                $this->pivot($buyer, 'housing')->rank,
                $rank->value . ' が成約に上書きされていない',
            );
        }
    }
}
