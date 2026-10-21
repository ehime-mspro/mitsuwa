<?php

namespace Tests\Feature\Approval\Phase5;

use App\Http\Controllers\Approval\PaperRecordController;
use App\Models\ApprovalPaperImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁の一覧と取り込みの記録（画面⑪。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から（D11）。
 */
class PaperListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));
        $this->w     = $this->approvalWorld();
        $this->admin = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listHtml(array $query = []): string
    {
        return $this->actingAs($this->admin)->get(route('approvals.admin.papers.index', $query))->assertOk()->getContent();
    }

    public function test_the_records_are_listed_newest_first(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'subject' => '古い決裁']);
        $this->paperRecord($this->w['dept'], ['seq' => 2, 'decided_on' => '2026-06-01', 'subject' => '新しい決裁', 'amount' => 30000000, 'applicant_name' => null]);

        $html = $this->listHtml();

        $this->assertStringContainsString('<span class="font-semibold tabular-nums">2</span> 件', $html);
        $this->assertLessThan(strpos($html, '古い決裁'), strpos($html, '新しい決裁'), '決裁日の新しい順');
        $this->assertStringContainsString('2026/06/01', $html);
        $this->assertStringContainsString('30,000,000円', $html);
        $this->assertStringContainsString('まだ取り込んでいません。', $html);
    }

    public function test_the_list_is_narrowed_by_the_year_and_the_department(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2025, 'seq' => 1, 'subject' => '前の年度の住宅']);
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2026, 'seq' => 1, 'subject' => '今年度の住宅']);
        $this->paperRecord($this->w['reviewDept'], ['fiscal_year' => 2026, 'seq' => 1, 'subject' => '今年度の総務']);

        $html = $this->listHtml(['year' => 2026, 'department' => $this->w['dept']->id]);
        $this->assertStringContainsString('今年度の住宅', $html);
        $this->assertStringNotContainsString('前の年度の住宅', $html);
        $this->assertStringNotContainsString('今年度の総務', $html);
        $this->assertStringContainsString('<option value="2026" selected>R8 年度（2026）</option>', $html);

        // 年度の選択肢は紙の決裁のある年度だけ
        $this->assertStringContainsString('<option value="2025" >R7 年度（2025）</option>', $html);
        $this->assertStringNotContainsString('value="2024"', $html);
    }

    /** 選択肢に無い値は外す（手で打った URL。断らない） */
    public function test_values_outside_the_choices_are_ignored(): void
    {
        $this->paperRecord($this->w['dept'], ['subject' => '残る決裁']);

        foreach ([['year' => 'abc'], ['year' => '1900'], ['department' => '999999'], ['department' => ['x']], ['year' => '02026']] as $query) {
            $html = $this->listHtml($query);
            $this->assertStringContainsString('残る決裁', $html, json_encode($query));
            $this->assertStringNotContainsString('条件を消す', $html, json_encode($query));
        }
    }

    /** ページ番号は最後のページまで（手で打った大きな番号でも 500 にしない。Bug #100） */
    public function test_a_page_past_the_end_shows_the_last_page(): void
    {
        for ($seq = 1; $seq <= PaperRecordController::PER_PAGE + 1; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq, 'decided_on' => '2026-05-07', 'subject' => sprintf('決裁%03d', $seq)]);
        }

        $html = $this->listHtml(['page' => 99]);

        $this->assertSame(1, substr_count($html, '決裁0'), '最後のページの 1 件だけ');
        $this->assertStringContainsString('決裁051', $html);
    }

    public function test_the_imports_are_listed_with_their_undoing(): void
    {
        $other = $this->baseUser(['name' => '取り消した 人']);
        ApprovalPaperImport::create(['file_name' => '07年度.xls', 'fiscal_year' => 2025, 'added_count' => 120, 'filled_count' => 3, 'imported_by' => $this->admin->id,
            'undone_at' => '2026-10-07 01:00:00', 'undone_by' => $other->id, 'undone_count' => 118]);
        ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 204, 'filled_count' => 0, 'imported_by' => $this->admin->id]);

        $html = $this->listHtml();

        $this->assertLessThan(strpos($html, '07年度.xls'), strpos($html, '08年度.xls'), '新しい順');
        $this->assertStringContainsString('204 件', $html);
        $this->assertStringContainsString('2026/10/07 10:00 に 取り消した 人 が取り消し（118 件を消した）', $html, '保存した日時は日本時間（Bug #61）');
    }

    /** 足した記録が 1 件も残っていない回（全部消した・足した 0 件）には取り消しのボタンを出さない（消す記録が無い）。残っている回には出す */
    public function test_an_import_without_remaining_records_has_no_undo_form(): void
    {
        $kept    = ApprovalPaperImport::create(['file_name' => '07年度.xls', 'fiscal_year' => 2025, 'added_count' => 1, 'filled_count' => 0, 'imported_by' => $this->admin->id]);
        $deleted = ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 1, 'filled_count' => 0, 'imported_by' => $this->admin->id]);
        $none    = ApprovalPaperImport::create(['file_name' => '09年度.xls', 'fiscal_year' => 2027, 'added_count' => 0, 'filled_count' => 2, 'imported_by' => $this->admin->id]);
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2025, 'seq' => 1, 'import_id' => $kept->id]);
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2026, 'seq' => 1, 'import_id' => $deleted->id])->delete();

        $html = $this->listHtml();
        $undo = fn (ApprovalPaperImport $import) => 'action="' . route('approvals.admin.papers.imports.undo', $import) . '"';
        $row  = fn (string $fileName) => collect(explode('</tr>', $html))->first(fn (string $chunk) => str_contains($chunk, $fileName));

        $this->assertStringContainsString($undo($kept), $html, '記録の残っている回には取り消しのフォーム');
        $this->assertStringContainsString('取り消す（今 1 件）', $row('07年度.xls'));
        $this->assertStringNotContainsString('この回に足した記録は残っていません', $row('07年度.xls'));
        foreach (['08年度.xls' => $deleted, '09年度.xls' => $none] as $fileName => $import) {
            $this->assertStringNotContainsString($undo($import), $html, "{$fileName}: 記録が無い回の取り消しのフォームは描かない");
            $this->assertStringNotContainsString('取り消す', $row($fileName), $fileName);
            $this->assertStringContainsString('—（この回に足した記録は残っていません）', $row($fileName), $fileName);
        }
        $this->assertSame(2, substr_count($html, 'この回に足した記録は残っていません'));
    }
}
