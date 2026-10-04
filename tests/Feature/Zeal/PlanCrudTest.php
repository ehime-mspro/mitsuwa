<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealPlan;
use Tests\Concerns\DrivesAlpineFetch;

/**
 * プランマスタ（Zeal\PlanController）: 一覧・登録・編集・削除。
 *
 * 一覧のリンクから作成・編集の画面を開き、描いたフォームを分解して送る（Bug #47）。
 * ⚠ 未チェックのチェックボックスはブラウザも送らない（parseForm も落とす）。チェックを入れるのは
 *   項目に '1' を足す、外すのは項目を消す。
 */
class PlanCrudTest extends MemberScreenTestCase
{
    use DrivesAlpineFetch;

    private function indexHtml(): string
    {
        return $this->actingAs($this->user)->get(route('zeal.plans.index'))->assertOk()->getContent();
    }

    /** 一覧に描かれたリンクを辿って開く */
    private function openFromIndex(string $href): string
    {
        $this->assertStringContainsString('href="' . $href . '"', $this->indexHtml(), "一覧に {$href} へのリンクが無い");

        return $this->actingAs($this->user)->get($href)->assertOk()->getContent();
    }

    public function test_the_list_shows_every_plan(): void
    {
        $html = $this->indexHtml();

        $this->assertStringContainsString('セミパーソナル通い放題', $html);
        $this->assertStringContainsString('パーソナル&amp;セミパーソナル月4回', $html);
    }

    public function test_a_plan_is_registered_from_the_create_screen(): void
    {
        $html = $this->openFromIndex(route('zeal.plans.create'));
        $form = $this->parseForm($html, 'action="' . route('zeal.plans.store') . '"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertSame('1', $form['fields']['active'] ?? null, '新規登録の「有効」が最初からチェックされていない');

        $response = $this->actingAs($this->user)->from(route('zeal.plans.create'))->post($form['action'], array_merge($form['fields'], [
            'name' => '朝活プラン', 'regular_price_excl' => '6500', 'campaign_price_excl' => '5000',
            'campaign_starts_on' => '2026-11-01', 'campaign_ends_on' => '2026-11-30',
            'max_concurrent_reservations' => '2', 'monthly_session_limit' => '8',
            'includes_personal' => '1', 'display_order' => '5',
        ]));

        $response->assertRedirect(route('zeal.plans.index'));
        $plan = ZealPlan::where('name', '朝活プラン')->firstOrFail();
        $this->assertSame(6500, $plan->regular_price_excl);
        $this->assertSame(5000, $plan->campaign_price_excl);
        $this->assertSame('2026-11-01', $plan->campaign_starts_on->format('Y-m-d'));
        $this->assertSame('2026-11-30', $plan->campaign_ends_on->format('Y-m-d'));
        $this->assertSame(2, $plan->max_concurrent_reservations);
        $this->assertSame(8, $plan->monthly_session_limit);
        $this->assertTrue($plan->includes_personal);
        $this->assertFalse($plan->includes_semi_personal);
        $this->assertFalse($plan->is_pair_plan);
        $this->assertSame(5, $plan->display_order);
        $this->assertTrue($plan->active);
        $this->assertStringContainsString('「朝活プラン」を登録しました。', $this->indexHtml());
    }

    /** 編集画面は今の値を描き、そのまま送ると変えた項目だけが変わる。チェックを外すと false になる */
    public function test_a_plan_is_updated_from_the_edit_screen(): void
    {
        $this->otherPlan->update([
            'campaign_price_excl' => 11000, 'campaign_starts_on' => '2026-10-01', 'campaign_ends_on' => '2026-12-31',
            'includes_personal' => true, 'includes_semi_personal' => true, 'monthly_session_limit' => 4, 'display_order' => 2, 'active' => true,
        ]);

        $html = $this->openFromIndex(route('zeal.plans.edit', $this->otherPlan));
        $form = $this->parseForm($html, 'action="' . route('zeal.plans.update', $this->otherPlan) . '"');
        $this->assertSame('PUT', $form['method']);
        $fields = $form['fields'];
        unset($fields['active']);   // 「有効」のチェックを外す

        $response = $this->actingAs($this->user)->from(route('zeal.plans.edit', $this->otherPlan))
            ->post($form['action'], array_merge($fields, ['regular_price_excl' => '13500']));

        $response->assertRedirect(route('zeal.plans.index'));
        $plan = $this->otherPlan->fresh();
        $this->assertSame(13500, $plan->regular_price_excl);
        $this->assertFalse($plan->active);
        // 触らなかった項目は、描いた値のまま戻ってくる
        $this->assertSame('パーソナル&セミパーソナル月4回', $plan->name);
        $this->assertSame(11000, $plan->campaign_price_excl);
        $this->assertSame('2026-10-01', $plan->campaign_starts_on->format('Y-m-d'));
        $this->assertSame('2026-12-31', $plan->campaign_ends_on->format('Y-m-d'));
        $this->assertTrue($plan->includes_personal);
        $this->assertTrue($plan->includes_semi_personal);
        $this->assertSame(4, $plan->monthly_session_limit);
        $this->assertSame(2, $plan->display_order);
        $this->assertStringContainsString('「パーソナル&amp;セミパーソナル月4回」を更新しました。', $this->indexHtml());
    }

    public function test_an_unused_plan_is_deleted_from_the_list(): void
    {
        $form = $this->parseForm($this->indexHtml(), 'action="' . route('zeal.plans.destroy', $this->otherPlan) . '"');
        $this->assertSame('DELETE', $form['method']);

        $response = $this->actingAs($this->user)->from(route('zeal.plans.index'))->post($form['action'], $form['fields']);

        $response->assertRedirect(route('zeal.plans.index'));
        $this->assertNull(ZealPlan::find($this->otherPlan->id));
        $this->assertNotNull(ZealPlan::find($this->plan->id));
        $this->assertStringContainsString('「パーソナル&amp;セミパーソナル月4回」を削除しました。', $this->indexHtml());
    }

    /** 契約で使っているプランは消さず、理由を一覧に出す */
    public function test_a_plan_used_by_a_contract_is_not_deleted(): void
    {
        $form = $this->parseForm($this->indexHtml(), 'action="' . route('zeal.plans.destroy', $this->plan) . '"');

        $response = $this->actingAs($this->user)->from(route('zeal.plans.index'))->post($form['action'], $form['fields']);

        $response->assertRedirect(route('zeal.plans.index'));
        $this->assertNotNull(ZealPlan::find($this->plan->id));
        $this->assertStringContainsString(
            '「セミパーソナル通い放題」は契約履歴で使用されているため削除できません。「有効」を無効にしてご利用ください。',
            $this->indexHtml(),
        );
    }

    /** 削除の確認は、名前に ' があっても壊れない（会員の削除の確認と同じ形を、プランでも守る） */
    public function test_the_delete_confirmation_survives_a_quote_in_the_plan_name(): void
    {
        $this->otherPlan->update(['name' => "O'Neil プラン"]);
        $html = $this->indexHtml();
        $action = 'action="' . route('zeal.plans.destroy', $this->otherPlan) . '"';
        $open = strrpos(substr($html, 0, strpos($html, $action)), '<form');
        $tag = substr($html, $open, strpos($html, '>', $open) - $open + 1);
        $this->assertSame(1, preg_match('/\sonsubmit="([^"]*)"/', $tag, $m), '削除フォームに onsubmit が無い');

        $declined = $this->runInlineHandler(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), false);

        $this->assertTrue($declined['compiled'], "削除の確認の JS が組み立てられない: {$declined['error']}");
        $this->assertSame(false, $declined['returned']);
        $this->assertSame(["「O'Neil プラン」を削除しますか？\n契約履歴で使用中のプランは削除できません。"], $declined['confirms']);
    }
}
