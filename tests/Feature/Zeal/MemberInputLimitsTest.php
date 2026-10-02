<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealMemberContract;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ZEAL 会員の画面（編集・プラン変更・退会）の入力チェックが、本番の列に入る値だけを通すこと（docs/RULES.md Bug #73）。
 *
 * ⚠ 直す前は、入力チェックが列より広かった。本番の MySQL（strict）は列に入らない値を断るので画面は 500 になるが、
 *   テストの SQLite は長さを見ないので保存できてしまう（Bug #40 / #54）。だからここでは「入力チェックが断ること」を見る。
 *   列の大きさは database/sql/create_zeal_tables.sql と同じで、本番の定義とも一致する（2026-10-02 に読み取りで確認）。
 * ⚠ 選択肢の項目は、モデルが enum として読むので、選択肢に無い値は保存の前に ValueError で 500 だった。
 */
class MemberInputLimitsTest extends MemberScreenTestCase
{
    // ================================================================
    // 1. 会員の編集・メール（列は 100 文字）
    // ================================================================

    /** $length 文字のメール（ローカル部 50 文字以下・ドメインのラベル 63 文字以下で、形式の検査は通る） */
    private static function email(int $length): string
    {
        $email = str_repeat('a', $length - 51) . '@' . str_repeat('b', 38) . '.example.com';
        self::assertSame($length, mb_strlen($email));

        return $email;
    }

    public function test_an_email_longer_than_the_column_is_refused(): void
    {
        $response = $this->sendEdit(['email' => self::email(101)]);

        $this->assertRefused($response, route('zeal.members.edit', $this->member), 'email');
        $this->assertNull($this->member->fresh()->email);
    }

    public function test_an_email_that_fills_the_column_is_saved(): void
    {
        $this->sendEdit(['email' => self::email(100)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('zeal.members.show', $this->member));

        $this->assertSame(self::email(100), $this->member->fresh()->email);
    }

    public function test_the_email_field_stops_at_the_column_length(): void
    {
        $html = $this->actingAs($this->user)->get(route('zeal.members.edit', $this->member))->assertOk()->getContent();

        $this->assertSame('100', $this->htmlAttr($this->tagWithId($html, 'input', 'email'), 'maxlength'));
    }

    // ================================================================
    // 2・3. プラン変更・備考（列は 200 文字）と適用価格（列は 0〜4294967295）
    // ================================================================

    public function test_a_plan_change_note_longer_than_the_column_is_refused(): void
    {
        $response = $this->sendPlanChange(['note' => str_repeat('あ', 201)]);

        $this->assertRefused($response, route('zeal.members.show', $this->member), 'note');
        $this->assertSame(1, ZealMemberContract::count());
    }

    public function test_a_plan_change_note_that_fills_the_column_is_saved(): void
    {
        $this->sendPlanChange(['note' => str_repeat('あ', 200)])->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('あ', 200), ZealMemberContract::orderByDesc('id')->value('note'));
    }

    public function test_the_plan_change_note_field_stops_at_the_column_length(): void
    {
        $this->assertSame('200', $this->htmlAttr($this->tagWithId($this->showHtml(), 'textarea', 'modal_note'), 'maxlength'));
    }

    public function test_a_plan_change_price_above_the_column_is_refused(): void
    {
        $response = $this->sendPlanChange(['applied_price_excl' => '4294967296']);

        $this->assertRefused($response, route('zeal.members.show', $this->member), 'applied_price_excl');
        $this->assertSame(1, ZealMemberContract::count());
    }

    public function test_a_plan_change_price_at_the_top_of_the_column_is_saved(): void
    {
        $this->sendPlanChange(['applied_price_excl' => '4294967295'])->assertSessionHasNoErrors();

        $this->assertSame(4294967295, ZealMemberContract::orderByDesc('id')->value('applied_price_excl'));
    }

    // ================================================================
    // 4. 選択肢の項目
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [項目, 選択肢に無い値, 選択肢の値] */
    public static function editChoices(): array
    {
        return [
            '当店を知ったきっかけ' => ['acquisition_source', 'tiktok', 'word_of_mouth'],
            '入会目的'             => ['purpose', 'yoga', 'lower_body'],
        ];
    }

    #[DataProvider('editChoices')]
    public function test_an_edit_choice_outside_the_options_is_refused(string $field, string $invalid, string $valid): void
    {
        $response = $this->sendEdit([$field => $invalid]);

        $this->assertRefused($response, route('zeal.members.edit', $this->member), $field);
        $this->assertNull($this->member->fresh()->getRawOriginal($field));
    }

    #[DataProvider('editChoices')]
    public function test_an_edit_choice_from_the_options_is_saved(string $field, string $invalid, string $valid): void
    {
        $this->sendEdit([$field => $valid])->assertSessionHasNoErrors();

        $this->assertSame($valid, $this->member->fresh()->getRawOriginal($field));
    }

    public function test_a_withdraw_reason_outside_the_options_is_refused(): void
    {
        $response = $this->sendWithdraw(['withdraw_reason' => 'bored']);

        $this->assertRefused($response, route('zeal.members.show', $this->member), 'withdraw_reason');
        $this->assertNull($this->member->fresh()->withdrew_on);
        $this->assertNull(ZealMemberContract::value('period_end'));
    }

    public function test_a_withdraw_reason_from_the_options_is_saved(): void
    {
        $this->sendWithdraw(['withdraw_reason' => 'busy'])->assertSessionHasNoErrors();

        $this->assertSame('busy', $this->member->fresh()->getRawOriginal('withdraw_reason'));
    }

    // ================================================================
    // 道具
    // ================================================================

    /** 断られて元の画面へ戻り、その項目にエラーが付いた（直す前は、保存されて詳細へ進むか、500） */
    private function assertRefused(TestResponse $response, string $backTo, string $field): void
    {
        $this->assertSame(302, $response->getStatusCode(), '断られずに ' . $response->getStatusCode() . ' になった');
        $this->assertSame($backTo, $response->headers->get('Location'), '元の画面へ戻っていない');
        $response->assertSessionHasErrors($field);
    }

    /** id で要素の開始タグを 1 つだけ取り出す */
    private function tagWithId(string $html, string $element, string $id): string
    {
        $this->assertSame(1, preg_match_all('/<' . $element . '\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m), "{$element}#{$id} がちょうど 1 つ無い");

        return $m[0][0];
    }
}
