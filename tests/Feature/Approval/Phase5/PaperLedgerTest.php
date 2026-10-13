<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\NameSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳と Excel に取り込んだ紙の決裁を並べる（要件 10・段階5 設計書 §5.13・§5.14・D10・D22・D23）。
 *
 * 紙の行: 同じ並び（決裁日の新しい順・同じ日は決裁No の順）・判断「不明」・状態の列に灰色の「紙」・行の先は紙の決裁の詳細・
 * Excel は区分「紙」で状態は空。絞り込みは表（§5.13）のとおり。見られる範囲は紙の規則（その部門の人・部門長・全件閲覧者・管理者）
 */
class PaperLedgerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->launchApprovals();
        $this->w = $this->approvalWorld();   // 住宅事業部 J・総務部 S
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 番号の付いた申請（並びと絞り込みだけを見るので、状態と番号は直接入れる） */
    private function decided(string $number, int $seq, string $decidedAt, string $subject = '社用車の購入'): ApprovalRequest
    {
        $request = $this->draftFor($this->w, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'decision' => 'approve', 'number' => $number, 'number_department_id' => $this->w['dept']->id,
            'number_fiscal_year' => 2026, 'number_seq' => $seq, 'round' => 1, 'decided_at' => $decidedAt, 'last_submitted_at' => $decidedAt,
        ]);

        return $request->refresh();
    }

    /** @return list<string> */
    private function keys(User $viewer, array $query = []): array
    {
        return Ledger::sortedKeys($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
    }

    private function paper(ApprovalPaperRecord $record): string
    {
        return Ledger::paperKey($record->id);
    }

    private function request(ApprovalRequest $request): string
    {
        return Ledger::requestKey($request->id);
    }

    public function test_papers_are_sorted_together_with_requests(): void
    {
        $r5  = $this->decided('R8-J-005', 5, '2026-06-01 01:00:00');                          // 日本時間 6/1
        $p4  = $this->paperRecord($this->w['dept'], ['seq' => 4, 'decided_on' => '2026-06-01']);   // 同じ日・小さい番号
        $p6  = $this->paperRecord($this->w['dept'], ['seq' => 6, 'decided_on' => '2026-06-01']);
        $old = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07']);
        $new = $this->paperRecord($this->w['reviewDept'], ['seq' => 1, 'decided_on' => '2026-06-02']);

        $this->assertSame(
            [$this->paper($new), $this->paper($p4), $this->request($r5), $this->paper($p6), $this->paper($old)],
            $this->keys($this->viewAllUser())
        );
    }

    /** 見られる紙の決裁だけ（その部門の人・部門長。ほかの部門の紙は出さない） */
    public function test_only_visible_papers_are_listed(): void
    {
        $mine  = $this->paperRecord($this->w['dept']);
        $other = $this->paperRecord($this->w['reviewDept']);

        $this->assertSame([$this->paper($mine)], $this->keys($this->w['applicant']));
        $this->assertSame([$this->paper($mine)], $this->keys($this->w['head']));
        $this->assertSame([], $this->keys($this->w['reviewer']), '審査担当者は所属していない部門（審査部門）の紙を見られない');
        $this->assertEqualsCanonicalizing([$this->paper($mine), $this->paper($other)], $this->keys($this->approvalAdmin()));
    }

    public function test_the_filters_apply_to_papers(): void
    {
        $viewer = $this->viewAllUser();
        $r      = $this->decided('R8-J-009', 9, '2026-06-01 01:00:00', '見本倉庫購入');
        $a      = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-10', 'applicant_name' => '甲野 一', 'subject' => '見本倉庫購入', 'note' => null]);
        $b      = $this->paperRecord($this->w['reviewDept'], ['seq' => 2, 'decided_on' => '2026-07-10', 'applicant_name' => '乙山', 'subject' => '事務所の改装', 'note' => '見本倉庫の隣']);
        $c      = $this->paperRecord($this->w['dept'], ['fiscal_year' => 2025, 'seq' => 3, 'decided_on' => '2025-06-10', 'applicant_name' => null, 'subject' => '前の年度']);

        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b)], $this->keys($viewer, ['year' => '2026', 'kind' => 'paper']), '年度は紙の年度');
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($c), $this->request($r)], $this->keys($viewer, ['department' => (string) $this->w['dept']->id]));
        $this->assertSame([$this->paper($b)], $this->keys($viewer, ['from' => '2026-07-10', 'to' => '2026-07-10']), '決裁日の期間は日付で（その日を含む）');
        $this->assertSame([$this->paper($a)], $this->keys($viewer, ['applicant' => '甲野一']), '申請者は台帳の文字（空白は無視）');
        $this->assertEqualsCanonicalizing([$this->request($r), $this->paper($a), $this->paper($b)], $this->keys($viewer, ['q' => '見本倉庫']), 'キーワードは件名と備考');

        // 紙が出ない条件（申請の種類・可／条可／否・進行中・取り下げ・区分「システム」）
        foreach ([['type' => (string) $this->w['type']->id], ['decision' => 'approve'], ['status' => 'progress'], ['status' => 'withdrawn'], ['kind' => 'system']] as $query) {
            $this->assertSame([], array_values(array_filter($this->keys($viewer, $query), fn (string $k) => str_starts_with($k, 'p'))), json_encode($query));
        }
        // 紙だけの条件（判断「不明」・区分「紙」）と、紙も出る状態（すべて）
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b), $this->paper($c)], $this->keys($viewer, ['decision' => 'unknown']));
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b), $this->paper($c)], $this->keys($viewer, ['kind' => 'paper']));
        $this->assertCount(4, $this->keys($viewer, ['status' => 'all']));
    }

    public function test_the_years_reach_back_to_the_oldest_paper(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2018, 'decided_on' => '2018-06-01']);

        $this->assertSame(range(2026, 2018), array_keys(Ledger::years()));
        $this->assertSame('H30 年度（2018）', Ledger::years()[2018]);
    }

    /** 紙の行（D23）。部門は先に読む（行ごとに問い合わせない） */
    public function test_a_paper_row_reads_as_a_paper(): void
    {
        $record = $this->paperRecord($this->w['dept'], ['seq' => 21, 'decided_on' => '2026-06-01', 'applicant_name' => '甲野', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000]);
        for ($seq = 22; $seq <= 30; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq]);
        }
        $keys    = array_map(fn (ApprovalPaperRecord $p) => Ledger::paperKey($p->id), ApprovalPaperRecord::orderBy('seq')->get()->all());
        $viewer  = $this->viewAllUser();
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $rows = Ledger::rows($viewer, $keys);

        $this->assertLessThanOrEqual(2, $queries, '紙の決裁ごとに問い合わせている（N+1）');
        /** @var LedgerRow $row */
        $row = $rows->first();
        $this->assertSame(
            [true, 'R8-J-021', '2026/06/01', '不明', '見本邸新築工事契約', null, '住宅事業部', '甲野', 50000000, 42000000, 8000000, 16.0, '', '紙', '紙', route('approvals.papers.show', $record)],
            [$row->isPaper, $row->number, \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d'), $row->decisionLabel, $row->subject, $row->typeName, $row->departmentName,
                $row->applicantName, $row->amount, $row->cost, $row->profit, $row->rate, $row->statusLabel, $row->kindLabel(), $row->badgeLabel(), $row->url()]
        );
    }

    public function test_the_screen_shows_papers_with_the_new_filters(): void
    {
        $record = $this->paperRecord($this->w['dept'], ['subject' => '見本倉庫購入']);

        $html = $this->actingAs($this->w['applicant'])->get(route('approvals.ledger.index', ['decision' => 'unknown', 'kind' => 'paper']))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="' . route('approvals.papers.show', $record) . '" class="text-emerald-600 hover:underline break-words">見本倉庫購入</a>', $html);
        $this->assertStringContainsString('>紙</span>', $html);
        $this->assertStringContainsString('<option value="unknown" selected>不明</option>', $html);
        $this->assertStringContainsString('<option value="paper" selected>紙</option>', $html);
        // ページ送りと Excel のリンクは条件を持ち回る
        $this->assertStringContainsString('href="' . e(route('approvals.ledger.excel', ['decision' => 'unknown', 'kind' => 'paper'])) . '"', $html);

        // 読み取れない区分は外して知らせる
        $this->actingAs($this->w['applicant'])->get(route('approvals.ledger.index', ['kind' => 'scan']))->assertOk()->assertSee('読み取れない条件があったので、外して探しました（区分）。');
    }

    public function test_the_excel_has_the_paper_rows_and_logs_the_new_filters(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 21, 'decided_on' => '2026-06-01', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000]);
        $viewer = $this->viewAllUser();

        $sheet = $this->sheet($viewer, ['decision' => 'unknown']);

        $this->assertSame(['区分', '状態'], [$sheet->getCell('S1')->getValue(), $sheet->getCell('T1')->getValue()]);
        $this->assertSame(['R8-J-021', '不明', '見本邸新築工事契約', null, '住宅事業部', '甲野', 50000000, 42000000, 8000000, '紙', null], array_map(
            fn (string $c) => $sheet->getCell("{$c}2")->getValue(),
            ['A', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'S', 'T']
        ));
        $this->assertSame('2026-06-01', Date::excelToDateTimeObject($sheet->getCell('B2')->getValue())->format('Y-m-d'));
        $this->assertSame('16.0%', $sheet->getCell('K2')->getFormattedValue());
        $this->assertSame('A1:T2', $sheet->getAutoFilter()->getRange());

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(['unknown', 'all', 1], [$log->filters['decision'], $log->filters['kind'], $log->request_count]);
    }

    /** 名前の列は決まったものだけを受け取る（SQL に埋め込むので、ほかの列を渡すと断る） */
    public function test_the_name_search_takes_only_known_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NameSearch::whereNameHasAll(User::query(), ['甲野'], 'users.password');
    }

    /** Excel の件数の上限は申請と紙を合わせて数える */
    public function test_the_excel_limit_counts_papers_too(): void
    {
        config(['approval.ledger.excel_limit' => 2]);
        $this->decided('R8-J-009', 9, '2026-06-01 01:00:00');
        $this->paperRecord($this->w['dept'], ['seq' => 1]);
        $this->paperRecord($this->w['dept'], ['seq' => 2]);

        $response = $this->actingAs($this->viewAllUser())->get(route('approvals.ledger.excel'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    private function sheet(User $viewer, array $query): Worksheet
    {
        $response = $this->actingAs($viewer)->get(route('approvals.ledger.excel', $query))->assertOk();
        $base     = tempnam(sys_get_temp_dir(), 'ledger');
        $path     = $base . '.xlsx';
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);
        @unlink($base);

        return $sheet;
    }
}
