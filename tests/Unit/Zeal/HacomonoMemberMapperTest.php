<?php

namespace Tests\Unit\Zeal;

use App\Support\Zeal\HacomonoMemberMapper;
use PHPUnit\Framework\TestCase;

class HacomonoMemberMapperTest extends TestCase
{
    private function mapper(): HacomonoMemberMapper
    {
        // name => id / name => price(excl) / store name => id
        $planId = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 1,
            'パーソナル&セミパーソナル通い放題（1枠）' => 2,
            'パーソナル&セミパーソナル月4回' => 3,
            'セミパーソナル通い放題' => 4,
            'ペアプラン' => 5,
        ];
        $planPrice = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 24000,
            'パーソナル&セミパーソナル通い放題（1枠）' => 18000,
            'パーソナル&セミパーソナル月4回' => 13000,
            'セミパーソナル通い放題' => 9800,
            'ペアプラン' => 20700,
        ];
        $storeId = ['松山市駅前店' => 1];
        return new HacomonoMemberMapper($planId, $planPrice, $storeId, 1, 10.0);
    }

    public function test_in_scope_includes_member_and_suspended_only(): void
    {
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '会員']));
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '停止中']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => 'ビジター']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => '']));
    }

    public function test_resolve_plan_maps_all_variants(): void
    {
        $m = $this->mapper();
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('（新）セミパーソナル通い放題', '')[0]);
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('セミパーソナル通い放題（松山市駅前）（1年契約）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル月4回', $m->resolvePlan('パーソナル&セミパーソナル月4回（松山市駅前）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル通い放題（1枠）', $m->resolvePlan('【松山市駅前】パーソナル&セミパーソナル通い放題(1枠)', '')[0]);
        $this->assertSame('ペアプラン', $m->resolvePlan('ペアプラン', '')[0]);
    }

    public function test_resolve_plan_prefers_custom2_then_course(): void
    {
        $m = $this->mapper();
        // カスタム2 が空なら コース名前 を使う
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('', '（新）セミパーソナル通い放題')[0]);
        // カスタム2 が NON_PLAN ラベルなら次へ（実データでは起きにくいが安全側）
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('休会プラン', 'セミパーソナル通い放題（松山市駅前）')[0]);
    }

    public function test_resolve_plan_returns_null_for_unmatched(): void
    {
        $m = $this->mapper();
        [$name, $raw, $src] = $m->resolvePlan('チケット会員', '');
        $this->assertNull($name);
        $this->assertSame('チケット会員', $raw);
    }
}
