<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStatus;
use App\Models\User;
use App\Support\Approval\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の画面（画面⑤。要件 10・14.4・段階4 設計書 §5.8・D22）。問い合わせの中身は LedgerQueryTest・LedgerRowTest が見る。
 */
class LedgerScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 決裁済み（可）の申請を、状態の列を書いて作る（並びと件数を見るテスト用） */
    private function approved(array $w, int $seq, array $attributes = []): int
    {
        $r = $this->submittedFor($w, $attributes);
        DB::table('approval_requests')->where('id', $r->id)->update([
            'status' => ApprovalStatus::Approved->value, 'decision' => 'approve', 'number' => 'R8-J-' . sprintf('%03d', $seq),
            'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => $seq, 'decided_at' => '2026-10-04 23:00:00',
        ]);

        return $r->id;
    }

    private function page(User $viewer, array $query = []): string
    {
        return (string) $this->actingAs($viewer)->get(route('approvals.ledger.index', $query))->assertOk()->getContent();
    }

    public function test_before_launch_the_ledger_is_not_open(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.ledger.index'))->assertRedirect(route('approvals.home'));
    }

    public function test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $id = $this->approved($w, 7, ['subject' => '社用車の購入', 'amount' => 2850000]);

        $html = $this->page($this->viewAllUser());

        $this->assertSame(1, preg_match('#<ul class="md:hidden[^"]*">(.*?)</ul>#s', $html, $cards), 'スマホのカードが無い');
        $this->assertSame(1, preg_match('#<div class="hidden md:block">\s*<div class="scroll-hint at-start">.*?(<table.*?</table>)#s', $html, $table), 'PC の表（横スクロールの枠の中）が無い');
        foreach (['card' => $cards[1], 'table' => $table[1]] as $where => $block) {
            $this->assertStringContainsString('href="' . route('approvals.requests.show', $id) . '"', $block, "{$where} から詳細へ行けない");
            foreach (['R8-J-007', '2026/10/05', '社用車の購入', '住宅事業部', '申請 花子', '2,850,000円', '決裁済み（可）'] as $text) {
                $this->assertStringContainsString($text, $block, "{$where} に「{$text}」が無い");
            }
        }
        $this->assertStringContainsString('>可</td>', $table[1], '表に判断が無い');
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">1</span> 件', $html);
    }

    public function test_what_people_typed_is_escaped(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->approved($w, 1, ['subject' => '<b>太字</b>の件名']);
        $w['applicant']->update(['name' => '<i>申請</i> 花子']);

        $html = $this->page($this->viewAllUser());

        $this->assertStringNotContainsString('<b>太字</b>', $html);
        $this->assertStringNotContainsString('<i>申請</i>', $html);
        // カードと表の両方で、打ったとおりの文字で出る（どちらか片方だけエスケープを外しても落ちるように数える）
        $this->assertSame(2, substr_count($html, '&lt;b&gt;太字&lt;/b&gt;の件名'), '件名がカードと表に 1 つずつ');
        $this->assertSame(2, substr_count($html, '&lt;i&gt;申請&lt;/i&gt; 花子'), '申請者がカードと表に 1 つずつ');

        // ⚠ キーワードの欄は別の画面で見る（「<script>」で絞ると行が 0 件になり、上の行の確かめが空振りする）
        $search = $this->page($this->viewAllUser(), ['q' => '<script>']);
        $this->assertStringContainsString('value="&lt;script&gt;"', $search, 'キーワードの欄も打ったとおり（タグにしない）');
    }

    public function test_the_form_shows_the_conditions_in_use(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser(), [
            'year' => '2026', 'department' => (string) $w['dept']->id, 'type' => (string) $w['type']->id, 'status' => 'progress',
            'decision' => 'reject', 'from' => '2026-05-01', 'to' => '2026-10-05', 'applicant' => '花子', 'q' => '社用車',
        ]);

        $this->assertMatchesRegularExpression('#<option value="2026" selected>R8 年度（2026）</option>#u', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $w['dept']->id . '" selected>[^<]*住宅事業部</option>#u', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $w['type']->id . '" selected>#', $html);
        $this->assertStringContainsString('<option value="progress" selected>進行中</option>', $html);
        $this->assertStringContainsString('<option value="reject" selected>否</option>', $html);
        foreach (['name="from" value="2026-05-01"', 'name="to" value="2026-10-05"', 'name="applicant" value="花子"', 'name="q" value="社用車"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringContainsString('>条件を消す</a>', $html);
    }

    public function test_the_default_shows_the_numbered_ones_and_says_so_when_empty(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w);   // 進行中（既定では出ない）

        $html = $this->page($this->viewAllUser());

        $this->assertStringContainsString('<option value="numbered" selected>決裁No の付いたもの</option>', $html);
        $this->assertStringContainsString('該当する申請はありません。', $html);
        $this->assertStringContainsString('状態は「決裁No の付いたもの」で絞っています', $html);
        $this->assertStringNotContainsString('>条件を消す</a>', $html, '既定のままなら消す条件は無い');
    }

    public function test_unreadable_conditions_are_named(): void
    {
        $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser(), ['from' => '2026-02-30', 'year' => 'abc']);

        $this->assertStringContainsString('読み取れない条件があったので、外して探しました（年度・決裁日（から））。', $html);
    }

    public function test_conditions_outside_the_choices_are_named_and_not_used(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->approved($w, 1);

        $html = $this->page($this->viewAllUser(), ['department' => '99999', 'year' => '1990']);

        $this->assertStringContainsString('読み取れない条件があったので、外して探しました（年度・申請部門）。', $html);
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">1</span> 件', $html, '外した条件で 0 件にしない');
    }

    public function test_pages_of_fifty_keep_the_conditions(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        foreach (range(1, Ledger::PER_PAGE + 1) as $seq) {
            $this->approved($w, $seq, ['subject' => "社用車 {$seq}"]);
        }
        $viewer = $this->viewAllUser();

        $first = $this->page($viewer, ['q' => '社用車']);
        $this->assertSame(Ledger::PER_PAGE, substr_count($first, 'class="text-emerald-600 hover:underline break-words"'), '1 ページは 50 件');
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">51</span> 件', $first);
        $this->assertStringContainsString(e(route('approvals.ledger.index', ['q' => '社用車', 'page' => 2])), $first, 'ページ送りが条件を運ぶ');

        $second = $this->page($viewer, ['q' => '社用車', 'page' => 2]);
        $this->assertSame(1, substr_count($second, 'class="text-emerald-600 hover:underline break-words"'));
        $this->assertStringContainsString('>R8-J-051<', $second, '2 ページ目は 51 番目（同じ日なので番号の順）');
    }

    public function test_the_page_does_not_query_per_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $viewer = $this->viewAllUser();
        $this->approved($w, 1);
        $this->approved($w, 2);

        $count = function () use ($viewer): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void { $queries++; });
            $this->page($viewer);

            return $queries;
        };

        $this->page($viewer);   // 見る人の決裁の印（approval_members）は 1 回目だけ読むので、先に 1 回開いておく
        $few = $count();
        foreach (range(3, 12) as $seq) {
            $this->approved($w, $seq);
        }
        $this->assertSame($few, $count(), '申請の数で問い合わせの数が増えた（N+1）');
    }

    public function test_the_filter_form_is_reset_whenever_the_page_is_shown(): void
    {
        $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser());

        $this->assertSame(1, substr_count($html, "'pageshow'"), 'pageshow のリスナーがちょうど 1 つでない');
        $this->assertMatchesRegularExpression(
            "#window\\.addEventListener\\('pageshow', function \\(\\) \\{\\s*document\\.getElementById\\('filter-form'\\)\\.reset\\(\\);\\s*\\}\\);#",
            $html,
            '戻ったときに絞り込みのフォームを表に使った条件へ戻していない（Bug #65）'
        );
        $this->assertStringContainsString('<form id="filter-form" method="GET"', $html);
    }
}
