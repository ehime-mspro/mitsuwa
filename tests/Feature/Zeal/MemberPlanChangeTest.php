<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealMemberContract;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ZEAL 会員のプラン変更（Zeal\MemberController::changePlan()）が保存できること（docs/RULES.md Bug #74）。
 *
 * ⚠ 直す前は、どの値を送っても 500 だった。DB::transaction() に渡す無名関数が `$request` を受け取っていないのに、
 *   中で `$request->boolean('is_campaign_applied')` を読んでいた（PHP 8 の未定義の変数の警告を Laravel が例外にする）。
 *   最初の実装（2026-05-02）からこの形で、会員の画面を通るテストが 1 本も無かったので見つからなかった。
 */
class MemberPlanChangeTest extends MemberScreenTestCase
{
    /** @return array<string, array{0: string, 1: bool}> [is_campaign_applied に送る値, 保存される値] */
    public static function campaignFlags(): array
    {
        return [
            '通常価格'           => ['0', false],
            'キャンペーン価格'   => ['1', true],
        ];
    }

    #[DataProvider('campaignFlags')]
    public function test_a_plan_change_closes_the_current_contract_and_opens_a_new_one(string $flag, bool $campaign): void
    {
        $this->sendPlanChange(['applied_price_excl' => '12000', 'is_campaign_applied' => $flag, 'note' => '月4回へ'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('zeal.members.show', $this->member));

        $contracts = ZealMemberContract::orderBy('id')->get();
        $this->assertCount(2, $contracts);
        $this->assertSame('2026-09-30', $contracts[0]->period_end?->format('Y-m-d'), '今の契約が変更日の前日で締まっていない');

        $new = $contracts[1];
        $this->assertSame($this->otherPlan->id, $new->plan_id);
        $this->assertSame('2026-10-01', $new->period_start->format('Y-m-d'));
        $this->assertNull($new->period_end);
        $this->assertSame(12000, $new->applied_price_excl);
        $this->assertSame($campaign, $new->is_campaign_applied);
        $this->assertSame('plan_change', $new->getRawOriginal('change_reason'));
        $this->assertSame('月4回へ', $new->note);
        $this->assertSame($this->otherPlan->id, $this->member->fresh()->current_plan_id);
    }
}
