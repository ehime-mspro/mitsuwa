<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealStore;
use App\Models\ZealTrainer;

/**
 * ZEAL 会員の編集画面が、保存されている値をそのまま保存できること（docs/RULES.md Bug #130）。
 *
 * 会員は hacomono の CSV 取込で入る。取込はフリガナ・性別が空でも入れ、メールの形式を見ない。
 * 直す前の編集画面はフリガナ・性別を必須にし、メールの形式を検査していたので、取込で入った会員は、
 * 電話番号だけ直したいときでも保存を断られた（本番の読み取り 2026-11-12: 在籍 40 人のうち 17 人がフリガナで・1 人が性別で）。
 * 選択肢も有効な店舗・トレーナーだけから作っていたので、無効にした店舗・トレーナーが付いた会員は、
 * そのまま保存すると担当が黙って外れた（Bug #82・#113 と同じ形）。
 *
 * 決めたこと（2026-11-13・利用者の決定）: 保存されている値が空のときに限り、フリガナ・性別の空欄を通す（一度入れると、次からは必須）。
 * 形の崩れたメールは、保存されている値と同じなら通す。無効の店舗・トレーナーは今の値に限って選択肢に残す（新しく選べるのは有効なものだけ）。
 */
class MemberEditSavedValuesTest extends MemberScreenTestCase
{
    private const OPTIONAL_HINT = 'まだ登録されていません。空のままでも保存できます（一度入れると、次からは必須になります）。';

    // ================================================================
    // 1. フリガナ・性別
    // ================================================================

    public function test_a_member_without_kana_and_gender_is_saved_as_it_is(): void
    {
        $this->member->update(['name_kana' => null, 'gender' => null]);

        $html = $this->editHtml();
        $this->assertDoesNotMatchRegularExpression('/\srequired(?=[\s>])/', $this->tagWithId($html, 'input', 'name_kana'), 'フリガナの欄に required が付いている（ブラウザが送信を止める）');
        $this->assertMatchesRegularExpression('/for="name_kana">\s*フリガナ<span class="optional">任意<\/span>/u', $html, 'フリガナのラベルが「任意」になっていない');
        $this->assertSame(self::OPTIONAL_HINT, $this->hint($html, 'name_kana_hint'));
        $this->assertSame(self::OPTIONAL_HINT, $this->hint($html, 'gender_hint'));

        $this->sendEdit(['phone' => '089-111-2222'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('zeal.members.show', $this->member));

        $fresh = $this->member->fresh();
        $this->assertSame('089-111-2222', $fresh->phone);
        $this->assertNull($fresh->name_kana);
        $this->assertNull($fresh->getRawOriginal('gender'));
    }

    public function test_kana_and_gender_once_filled_in_become_required(): void
    {
        $this->member->update(['name_kana' => null, 'gender' => null]);

        $this->sendEdit(['name_kana' => 'ニュウリョク タロウ', 'gender' => 'female'])->assertSessionHasNoErrors();
        $this->assertSame('ニュウリョク タロウ', $this->member->fresh()->name_kana);
        $this->assertSame('female', $this->member->fresh()->getRawOriginal('gender'));

        $this->assertKanaAndGenderRequired();
    }

    public function test_a_member_with_kana_and_gender_still_needs_them(): void
    {
        $this->assertKanaAndGenderRequired();
    }

    // ================================================================
    // 2. メール（取込は形式を見ない）
    // ================================================================

    public function test_a_saved_malformed_email_is_kept_as_it_is(): void
    {
        $this->member->update(['email' => 'yamada@']);

        $tag = $this->tagWithId($this->editHtml(), 'input', 'email');
        $this->assertSame('text', $this->htmlAttr($tag, 'type'), 'メールの欄が type="email" だと、ブラウザが保存済みの崩れた値で送信を止める');
        $this->assertSame('email', $this->htmlAttr($tag, 'inputmode'));

        $this->sendEdit(['phone' => '089-111-2222'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('zeal.members.show', $this->member));

        $this->assertSame('yamada@', $this->member->fresh()->email);
        $this->assertSame('089-111-2222', $this->member->fresh()->phone);
    }

    public function test_a_changed_email_is_checked(): void
    {
        $this->member->update(['email' => 'yamada@']);

        $response = $this->sendEdit(['email' => 'tanaka@']);
        $this->assertRefused($response, route('zeal.members.edit', $this->member), 'email');
        $this->assertSame('yamada@', $this->member->fresh()->email);

        $this->sendEdit(['email' => 'tanaka@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('tanaka@example.com', $this->member->fresh()->email);
    }

    // ================================================================
    // 3. 無効にした店舗・トレーナー
    // ================================================================

    public function test_an_inactive_store_and_trainer_stay_selected_and_are_kept(): void
    {
        [$store, $trainer] = $this->inactiveStoreAndTrainer();

        $html = $this->editHtml();
        $this->assertMatchesRegularExpression('/<option value="' . $store->id . '"\s+selected>\s*松山市駅前店（無効）\s*<\/option>/u', $html, '今の店舗が「（無効）」付きで選ばれていない');
        $this->assertMatchesRegularExpression('/<option value="' . $trainer->id . '"\s+selected>\s*休職 トレーナー（無効）\s*<\/option>/u', $html, '今の担当が「（無効）」付きで選ばれていない');
        $this->assertMatchesRegularExpression('/>\s*大街道店\s*<\/option>/u', $html, '有効な店舗に「（無効）」が付いている');

        // ⚠ ParsesForms は selected が無いと先頭の option を送る。今の値が選択肢から消えると、店舗は「選択してください」、担当は「未設定」になって落ちる
        $this->sendEdit(['phone' => '089-111-2222'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('zeal.members.show', $this->member));

        $fresh = $this->member->fresh();
        $this->assertSame($store->id, $fresh->store_id);
        $this->assertSame($trainer->id, $fresh->trainer_id);
    }

    public function test_another_inactive_store_or_trainer_is_refused(): void
    {
        [$store, $trainer] = $this->inactiveStoreAndTrainer();
        $closedStore = ZealStore::create(['name' => '閉店した店', 'display_order' => 3, 'active' => false]);
        $leftTrainer = ZealTrainer::create(['name' => '退職 トレーナー', 'display_order' => 3, 'active' => false]);

        $this->assertRefused($this->sendEdit(['store_id' => (string) $closedStore->id]), route('zeal.members.edit', $this->member), 'store_id');
        $this->assertRefused($this->sendEdit(['trainer_id' => (string) $leftTrainer->id]), route('zeal.members.edit', $this->member), 'trainer_id');

        $fresh = $this->member->fresh();
        $this->assertSame($store->id, $fresh->store_id);
        $this->assertSame($trainer->id, $fresh->trainer_id);
    }

    public function test_an_active_store_and_trainer_can_be_chosen(): void
    {
        $this->inactiveStoreAndTrainer();
        $activeStore = ZealStore::where('name', '大街道店')->firstOrFail();
        $activeTrainer = ZealTrainer::where('name', '在籍 トレーナー')->firstOrFail();

        $this->sendEdit(['store_id' => (string) $activeStore->id, 'trainer_id' => (string) $activeTrainer->id])->assertSessionHasNoErrors();

        $fresh = $this->member->fresh();
        $this->assertSame($activeStore->id, $fresh->store_id);
        $this->assertSame($activeTrainer->id, $fresh->trainer_id);
    }

    // ================================================================
    // 道具
    // ================================================================

    /** フリガナ・性別のある会員: フリガナの欄に required があり、案内は出ず、空にする・性別の欄の無い送信は断る */
    private function assertKanaAndGenderRequired(): void
    {
        $html = $this->editHtml();
        $this->assertMatchesRegularExpression('/\srequired(?=[\s>])/', $this->tagWithId($html, 'input', 'name_kana'), 'フリガナの欄に required が無い');
        $this->assertStringNotContainsString(self::OPTIONAL_HINT, $html);

        $kana = $this->member->fresh()->name_kana;
        $this->assertRefused($this->sendEdit(['name_kana' => '']), route('zeal.members.edit', $this->member), 'name_kana');
        $this->assertSame($kana, $this->member->fresh()->name_kana);

        $gender = $this->member->fresh()->getRawOriginal('gender');
        $this->assertRefused($this->sendEdit([], ['gender']), route('zeal.members.edit', $this->member), 'gender');
        $this->assertSame($gender, $this->member->fresh()->getRawOriginal('gender'));
    }

    /**
     * 土台の店舗（松山市駅前店）を無効にし、無効のトレーナーを担当にする。有効な店舗・トレーナーも 1 つずつ作る。
     *
     * @return array{0: ZealStore, 1: ZealTrainer} 今の店舗と担当（どちらも無効）
     */
    private function inactiveStoreAndTrainer(): array
    {
        $store = $this->member->store;
        $store->update(['active' => false]);
        ZealStore::create(['name' => '大街道店', 'display_order' => 2, 'active' => true]);

        $trainer = ZealTrainer::create(['name' => '休職 トレーナー', 'display_order' => 1, 'active' => false]);
        ZealTrainer::create(['name' => '在籍 トレーナー', 'display_order' => 2, 'active' => true]);
        $this->member->update(['trainer_id' => $trainer->id]);

        return [$store->fresh(), $trainer];
    }

    /** id の付いた案内の文 */
    private function hint(string $html, string $id): ?string
    {
        return preg_match('/<div[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/div>/su', $html, $m) ? trim($m[1]) : null;
    }
}
