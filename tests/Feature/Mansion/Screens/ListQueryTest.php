<?php

namespace Tests\Feature\Mansion\Screens;

/**
 * 賃貸マンションの一覧・登録画面は、手で組んだ URL（配列・数字でない年度）でも 500 にしない（Bug #97 の形）。
 * 崩れた値は「指定が無い」として扱う。
 */
class ListQueryTest extends MansionScreenTestCase
{
    public function test_malformed_queries_are_ignored_instead_of_failing(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $tenant);
        $this->parkingContract($this->parking('A-1'), $tenant);

        $cases = [
            '/mansion/contracts?fiscal_year=abc' => '佐藤 花子',
            '/mansion/contracts?fiscal_year[]=2026' => '佐藤 花子',
            '/mansion/contracts?property_id[]=1' => '佐藤 花子',
            '/mansion/contracts?status[]=active' => '佐藤 花子',
            '/mansion/parking-contracts?fiscal_year=abc' => '佐藤 花子',
            '/mansion/parking-contracts?property_id[]=1' => '佐藤 花子',
            '/mansion/parking-contracts?link_type[]=standalone' => '佐藤 花子',
            '/mansion/properties?keyword[]=a' => 'ミツワレジデンス',
            '/mansion/properties?ownership_type[]=managed' => 'ミツワレジデンス',
            '/mansion/tenants?keyword[]=a' => '佐藤 花子',
            '/mansion/tenants?tenant_type[]=resident' => '佐藤 花子',
            '/mansion/contracts/create?room_id[]=1' => '部屋契約 新規登録',
            '/mansion/parking-contracts/create?parking_id[]=1' => '駐車場契約 新規登録',
        ];
        $failed = [];
        foreach ($cases as $url => $expected) {
            $response = $this->actingAs($this->user)->get($url);
            if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->getContent(), $expected)) {
                $failed[] = $url . ' => ' . $response->getStatusCode();
            }
        }
        $this->assertSame([], $failed, '崩れた URL で一覧・画面が開けない');
    }

    public function test_well_formed_filters_still_work(): void
    {
        $other = $this->property(['property_code' => 'MS-002', 'property_name' => '湊町ハイツ']);
        $this->contract($this->room('101'), $this->tenant('佐藤 花子'), ['contract_date' => '2026-06-01']);
        $this->contract($this->room('201', 'vacant', ['property_id' => $other->id]), $this->tenant('鈴木 一郎'), ['contract_date' => '2024-06-01']);

        $html = $this->htmlOf(route('mansion.contracts.index', ['property_id' => $other->id]));
        $this->assertStringContainsString('鈴木 一郎', $html);
        $this->assertStringNotContainsString('佐藤 花子', $html);

        $html = $this->htmlOf(route('mansion.contracts.index', ['fiscal_year' => '2026']));
        $this->assertStringContainsString('佐藤 花子', $html);
        $this->assertStringNotContainsString('鈴木 一郎', $html);
    }
}
