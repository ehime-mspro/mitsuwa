<?php

namespace Tests\Feature\Zeal;

use Illuminate\Support\Facades\DB;

/**
 * 体験予約の詳細（GET zeal/inquiries/{id}。Zeal\InquiryController::show）。
 *
 * 体験予約は外部の DB（'zeal' 接続）を読むだけの画面。土台が SQLite のメモリへ向け直している。
 * 開くのは一覧の行のリンクから（描いた href を辿る）。
 */
class InquiryShowTest extends MemberScreenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('zeal')->table('gym_inquiries')->insert([
            ['id' => 7, 'name' => '体験 花子', 'status' => '入会', 'inquiry_date' => '2026-09-01', 'trial_date' => '2026-09-05',
             'trial_time' => '10:30:00', 'contract_plan' => '1枠 通い放題', 'gender' => '女性', 'age' => 34,
             'phone' => '090-1111-2222', 'email' => 'hanako@example.com', 'purpose' => 'ダイエット',
             'purpose_detail' => '夏までに 3kg', 'memo' => '平日夜', 'special_notes' => '腰痛あり'],
            ['id' => 8, 'name' => '未入会 次郎', 'status' => '未入会', 'inquiry_date' => '2026-09-02', 'trial_date' => null,
             'trial_time' => null, 'contract_plan' => null, 'gender' => null, 'age' => null,
             'phone' => null, 'email' => null, 'purpose' => null, 'purpose_detail' => null, 'memo' => null, 'special_notes' => null],
        ]);
        $this->member->update(['gym_inquiry_id' => 7]);
    }

    /** 一覧の行のリンクを辿って開く */
    private function openFromList(int $id): string
    {
        $list = $this->actingAs($this->user)->get(route('zeal.inquiries.index'))->assertOk()->getContent();
        $href = route('zeal.inquiries.show', $id);
        $this->assertStringContainsString('href="' . $href . '"', $list, "一覧に体験予約 {$id} へのリンクが無い");

        return $this->actingAs($this->user)->get($href)->assertOk()->getContent();
    }

    public function test_the_detail_shows_the_reservation_and_links_the_member_who_joined(): void
    {
        $html = $this->openFromList(7);

        foreach (['体験 花子', '2026年09月01日', '2026年09月05日', '10:30', '34歳', '090-1111-2222', 'hanako@example.com',
                  'ダイエット', '夏までに 3kg', '平日夜', '腰痛あり', 'この体験予約から会員登録された方がいます。'] as $text) {
            $this->assertStringContainsString($text, $html, "詳細に「{$text}」が出ていない");
        }
        // 契約プランは「N枠」を除いて出す（GymInquiry::getContractPlanDisplayAttribute）
        $this->assertMatchesRegularExpression('/font-weight: 700; color: #047857;">通い放題<\/div>/u', $html);

        // 紐づく会員へのリンクを辿ると、その会員の詳細が開く
        $memberHref = route('zeal.members.show', $this->member->id);
        $this->assertStringContainsString('href="' . $memberHref . '"', $html);
        $this->actingAs($this->user)->get($memberHref)->assertOk()->assertSee('在籍 太郎');
    }

    public function test_a_reservation_without_a_member_says_so(): void
    {
        $html = $this->openFromList(8);

        $this->assertStringContainsString('未入会 次郎', $html);
        $this->assertStringContainsString('この体験予約に紐付く会員はまだ登録されていません。', $html);
        $this->assertStringNotContainsString('この体験予約から会員登録された方がいます。', $html);
    }

    public function test_an_unknown_reservation_is_not_found(): void
    {
        $this->actingAs($this->user)->get(route('zeal.inquiries.show', 999))->assertNotFound();
    }
}
