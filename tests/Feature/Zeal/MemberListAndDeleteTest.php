<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\DrivesAlpineFetch;

/**
 * 会員の一覧（GET zeal/members）と削除（DELETE zeal/members/{member}。経営層のみ）。
 *
 * 一覧は絞り込みのフォーム（id="filter-form"・GET）を分解して送る。削除は詳細画面に描かれた削除フォームから送る。
 */
class MemberListAndDeleteTest extends MemberScreenTestCase
{
    use DrivesAlpineFetch;

    private ZealMember $joinedInMarch;

    private ZealMember $withdrawn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->joinedInMarch = ZealMember::create([
            'store_id' => $this->member->store_id, 'name' => '春入会 花子', 'name_kana' => 'ハルニュウカイ ハナコ', 'gender' => 'female',
            'phone' => '080-3333-4444', 'email' => 'haru@example.com',
            'joined_on' => '2026-03-05', 'current_plan_id' => $this->otherPlan->id, 'created_by' => $this->user->id,
        ]);
        $this->withdrawn = ZealMember::create([
            'store_id' => $this->member->store_id, 'name' => '退会 三郎', 'gender' => 'male',
            'joined_on' => '2024-12-10', 'withdrew_on' => '2026-01-31', 'current_plan_id' => $this->plan->id, 'created_by' => $this->user->id,
        ]);
    }

    /** 一覧の絞り込みのフォームを分解し、$over の項目だけ差し替えて送る。返すのは一覧に並んだ会員の id */
    private function listed(array $over = []): array
    {
        $html = $this->actingAs($this->user)->get(route('zeal.members.index'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'id="filter-form"');
        $this->assertSame('GET', $form['method']);

        $result = $this->actingAs($this->user)->get($form['action'] . '?' . http_build_query(array_merge($form['fields'], $over)))->assertOk()->getContent();

        return array_values(array_filter(
            [$this->member->id, $this->joinedInMarch->id, $this->withdrawn->id],
            fn (int $id) => str_contains($result, 'href="' . route('zeal.members.show', $id) . '"'),
        ));
    }

    public function test_the_list_shows_active_members_by_default(): void
    {
        $this->assertSame([$this->member->id, $this->joinedInMarch->id], $this->listed());
    }

    public function test_the_status_filter_switches_between_active_withdrawn_and_all(): void
    {
        $this->assertSame([$this->withdrawn->id], $this->listed(['status' => 'withdrew']));
        $this->assertSame([$this->member->id, $this->joinedInMarch->id, $this->withdrawn->id], $this->listed(['status' => 'all']));
    }

    public function test_the_plan_gender_and_keyword_filters_narrow_the_list(): void
    {
        $this->assertSame([$this->joinedInMarch->id], $this->listed(['plan_id' => (string) $this->otherPlan->id]));
        $this->assertSame([$this->joinedInMarch->id], $this->listed(['gender' => 'female']));
        $this->assertSame([$this->joinedInMarch->id], $this->listed(['keyword' => 'haru@']));
        $this->assertSame([$this->member->id], $this->listed(['keyword' => 'ザイセキ']));
    }

    /** 入会月の選択肢は、入会日のある月を新しい順に重複なく並べる（退会した会員の月も含む） */
    public function test_the_joined_month_options_list_each_month_newest_first(): void
    {
        ZealMember::create([
            'store_id' => $this->member->store_id, 'name' => '同月 四郎', 'joined_on' => '2026-03-28', 'created_by' => $this->user->id,
        ]);
        $html = $this->actingAs($this->user)->get(route('zeal.members.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<select name="joined_month".*?<\/select>/s', $html, $select));
        preg_match_all('/<option value="([^"]*)"/', $select[0], $values);
        $this->assertSame(['', '2026-03', '2025-10', '2024-12'], $values[1]);
    }

    public function test_the_joined_month_filter_keeps_members_who_joined_in_that_month(): void
    {
        $this->assertSame([$this->joinedInMarch->id], $this->listed(['joined_month' => '2026-03']));
        $this->assertSame([$this->withdrawn->id], $this->listed(['joined_month' => '2024-12', 'status' => 'all']));
        // 選択肢に無い月・形の崩れた値は 0 件（MySQL の DATE_FORMAT で比べていたときと同じ）
        $this->assertSame([], $this->listed(['joined_month' => '2026-04']));
        $this->assertSame([], $this->listed(['joined_month' => '2026-3']));
        $this->assertSame([], $this->listed(['joined_month' => 'abc']));
    }

    /** 詳細画面の削除フォームを分解して送ると、会員と契約が消えて一覧に戻る */
    public function test_deleting_from_the_detail_screen_removes_the_member_and_the_contracts(): void
    {
        $show = route('zeal.members.show', $this->member);
        $form = $this->parseForm($this->showHtml(), 'action="' . route('zeal.members.destroy', $this->member) . '"');
        $this->assertSame('DELETE', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);

        $response = $this->actingAs($this->user)->from($show)->post($form['action'], $form['fields']);

        $response->assertRedirect(route('zeal.members.index'));
        $this->assertNull(ZealMember::find($this->member->id));
        $this->assertSame(0, ZealMemberContract::where('member_id', $this->member->id)->count());
        $this->assertNotNull(ZealMember::find($this->joinedInMarch->id), '別の会員まで消えた');
        $this->actingAs($this->user)->get(route('zeal.members.index'))->assertSee('「在籍 太郎」を削除しました。');
    }

    /**
     * 削除の確認（onsubmit の confirm）は、名前に ' や \ があっても壊れない。
     * ⚠ 名前を JS の文字列に生で埋め込むと、ブラウザが属性の実体参照を戻した時点で文字列が閉じ、
     *   関数が組み立てられない（＝確認を出さずに送信される）。
     */
    #[DataProvider('namesThatBreakAJsString')]
    public function test_the_delete_confirmation_survives_any_member_name(string $name): void
    {
        $this->member->update(['name' => $name]);

        $handler = $this->deleteOnSubmit();

        $declined = $this->runInlineHandler($handler, false);
        $this->assertTrue($declined['compiled'], "削除の確認の JS が組み立てられない: {$declined['error']}");
        $this->assertSame(false, $declined['returned'], '確認で「いいえ」を選んでも送信が止まらない');
        $this->assertSame(['「' . $name . '」を削除します。この操作は取り消せません。よろしいですか？'], $declined['confirms']);

        $this->assertSame(true, $this->runInlineHandler($handler, true)['returned'], '確認で「はい」を選んでも送信されない');
    }

    public static function namesThatBreakAJsString(): array
    {
        return [
            'シングルクォート' => ["O'Brien 太郎"],
            'バックスラッシュ' => ['山田 太郎\\'],
            '閉じて続ける'     => ["x');alert(1);('"],
        ];
    }

    /** 詳細画面の削除フォームの onsubmit（ブラウザが JS に渡す文字列） */
    private function deleteOnSubmit(): string
    {
        $html = $this->showHtml();
        $action = 'action="' . route('zeal.members.destroy', $this->member) . '"';
        $pos = strpos($html, $action);
        $this->assertNotFalse($pos, '削除フォームが無い');
        $open = strrpos(substr($html, 0, $pos), '<form');
        $tag = substr($html, $open, strpos($html, '>', $pos) - $open + 1);
        $this->assertSame(1, preg_match('/\sonsubmit="([^"]*)"/', $tag, $m), '削除フォームに onsubmit が無い');

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
}
