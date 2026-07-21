<?php

namespace Tests\Unit\RealEstate;

use App\Enums\ProcurementStatus;
use PHPUnit\Framework\TestCase;

/**
 * 仕入れ案件ステータス enum の仕様を固定する（DB 非依存）。
 * 「販売済」は契約登録時に自動で入り、一覧の既定フィルタから外れる。
 */
class ProcurementStatusTest extends TestCase
{
    public function test_sold_case_exists_with_expected_value(): void
    {
        $this->assertSame('sold', ProcurementStatus::Sold->value);
    }

    public function test_sold_label(): void
    {
        $this->assertSame('販売済', ProcurementStatus::Sold->label());
    }

    public function test_sold_badge_class(): void
    {
        $this->assertSame('badge-re-sold', ProcurementStatus::Sold->badgeClass());
    }

    /**
     * enum の定義順がそのままフィルタセレクト・編集フォームの表示順になるため、
     * 「販売中 → 販売済 → 不成約」の並びを仕様として固定する。
     */
    public function test_cases_are_ordered_with_sold_between_selling_and_lost(): void
    {
        $values = array_column(ProcurementStatus::cases(), 'value');

        $this->assertSame([
            'info_obtained',
            'site_survey',
            'assessment',
            'negotiating',
            'contracted',
            'settled',
            'selling',
            'sold',
            'lost',
        ], $values);
    }

    /**
     * isClosed() は「不成約」だけを指す。販売済まで closed 扱いに広げると
     * 一覧の非表示判定が enum 側とフィルタ側で二重化し、解釈が割れるため意図的に false。
     */
    public function test_sold_is_not_closed(): void
    {
        $this->assertFalse(ProcurementStatus::Sold->isClosed());
        $this->assertTrue(ProcurementStatus::Lost->isClosed());
    }
}
