<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 台帳の行と Excel の 4 列（工事原価・粗利益金額・粗利率・契約予定日。要件 10・段階5 設計書 §5.8・D18・D19）と、
 * 台帳を触るついでに片付けた 4b の小さな指摘（設計書 §8）。
 */
class LedgerExcelTableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:12:00', 'UTC'));   // 日本時間 10/5 1:12
        $this->workflow = app(Workflow::class);
        $this->launchApprovals();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出し、社長が可と決裁した申請 */
    private function decidedContract(array $w, ApprovalType $type): ApprovalRequest
    {
        $r = $this->submittedFor($w, [
            'type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'amount' => 41700000, 'staff' => '佐藤', 'contract_date' => '2026-10-20',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('オプション工事', false, 1200000, 850000), $this->row('紹介料', true, 0, 300000)],
                'lower'    => [$this->row('土地契約金額', true, 12000000, 10500000)],
            ],
        ]);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        return $r->fresh();
    }

    private function sheet(User $viewer, array $query = []): Worksheet
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

    private function rowFor(User $viewer, ApprovalRequest $request): LedgerRow
    {
        return Ledger::rows($viewer, [$request->id])->sole();
    }

    public function test_the_four_columns_of_a_table_request(): void
    {
        $w = $this->approvalWorld();
        $this->decidedContract($w, $this->housingContractType($w));

        $sheet = $this->sheet($this->viewAllUser());

        $this->assertSame(['金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日'], $sheet->rangeToArray('H1:M1')[0]);
        foreach (['H2' => 41700000, 'I2' => 33650000, 'J2' => 8050000] as $cell => $value) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "{$cell} が数でない");
            $this->assertEquals($value, $sheet->getCell($cell)->getValue());
            $this->assertSame('#,##0', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
        // 粗利率は割合の数（Excel で 19.3% と出る。並べ替え・計算ができる。D19）
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('K2')->getDataType());
        $this->assertEquals(0.193, $sheet->getCell('K2')->getValue());
        $this->assertSame('0.0%', $sheet->getStyle('K2')->getNumberFormat()->getFormatCode());
        $this->assertSame('19.3%', $sheet->getCell('K2')->getFormattedValue());
        // 契約予定日は Excel の日付（その日。時刻を入れない）
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M2')->getDataType());
        $this->assertSame('2026-10-20 00:00:00', Date::excelToDateTimeObject($sheet->getCell('M2')->getValue())->format('Y-m-d H:i:s'));
        $this->assertSame('yyyy/mm/dd', $sheet->getStyle('M2')->getNumberFormat()->getFormatCode());
        $this->assertSame('A1:S2', $sheet->getAutoFilter()->getRange());
    }

    /** 5W2H の種類でも契約予定日を使う設定なら、Excel の契約予定日に入る（要件 10・D12。工事原価・粗利益金額・粗利率は空。点検の M-6） */
    public function test_a_points_type_that_uses_the_contract_date_fills_only_that_column(): void
    {
        $w = $this->approvalWorld();
        $w['type']->update(['uses_contract_date' => true]);
        $this->submittedFor($w, ['contract_date' => '2026-10-20']);

        $sheet = $this->sheet($this->viewAllUser(), ['status' => 'all']);
        $this->assertSame([null, null, null], [$sheet->getCell('I2')->getValue(), $sheet->getCell('J2')->getValue(), $sheet->getCell('K2')->getValue()]);
        $this->assertSame('2026-10-20 00:00:00', Date::excelToDateTimeObject($sheet->getCell('M2')->getValue())->format('Y-m-d H:i:s'));
    }

    /** 販売金額の合計が 0 の明細表（申請者本人の直しかけ）は粗利率を空にする（「—」の文字を入れると列で並べ替えや計算ができない。D19） */
    public function test_the_rate_is_empty_when_the_total_sale_is_zero(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w, [
            'type_id' => $this->housingContractType($w)->id, 'staff' => '佐藤', 'contract_date' => '2026-10-20',
            'amount_table' => ['subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, 900)], 'lower' => []],
        ]);
        $this->workflow->judgeHead($request->fresh(), $w['head'], $request->fresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $request->fresh()->update(['amount_table' => ['subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 0, 900)], 'lower' => []]]);

        $own = $this->rowFor($w['applicant'], $request);
        $this->assertSame([900, -900, null], [$own->cost, $own->profit, $own->rate], '申請者本人には今の中身');

        $sheet = $this->sheet($w['applicant'], ['status' => 'all']);
        $this->assertEquals(900, $sheet->getCell('I2')->getValue());
        $this->assertNull($sheet->getCell('K2')->getValue());

        // ほかの人には最後に提出した中身（販売金額 1,000・粗利率 10%）
        $others = $this->rowFor($this->viewAllUser(), $request);
        $this->assertSame([900, 100, 10.0], [$others->cost, $others->profit, $others->rate]);
        $this->assertSame('2026-10-20', $others->contractDate?->format('Y-m-d'));
    }

    /** 控えは今の回のものだけを使う（詳細の RequestContent と同じ。今の回の控えが欠けていれば今の中身へ落とさずに空。4b の Task 2 の軽微） */
    public function test_a_row_uses_only_the_revision_of_the_current_round(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => 2]);   // 2 回目の控えが欠けた

        $row = $this->rowFor($this->viewAllUser(), $request);
        $this->assertNull($row->subject, '前の回の控えを今の回として出さない');
        $this->assertSame('社用車の購入', $this->rowFor($w['applicant'], $request)->subject, '申請者本人には今の中身');
    }

    /** 決裁日の期間は 1900〜2099 年だけを読む（0000-01-01 は UTC に直すと年が -1 になり、MySQL の TIMESTAMP と比べられない） */
    public function test_a_decided_date_outside_the_years_is_left_out(): void
    {
        $filter = LedgerFilter::fromRequest(Request::create('/', 'GET', ['from' => '0000-01-01', 'to' => '2100-01-01']));
        $this->assertSame([null, null, ['決裁日（から）', '決裁日（まで）']], [$filter->decidedFrom, $filter->decidedTo, $filter->ignored]);

        $kept = LedgerFilter::fromRequest(Request::create('/', 'GET', ['from' => '1900-01-01', 'to' => '2099-12-31']));
        $this->assertSame(['1900-01-01', '2099-12-31'], [$kept->decidedFrom?->format('Y-m-d'), $kept->decidedTo?->format('Y-m-d')]);
    }

    /** 状態の「すべて」は下書き以外すべて（知らないキーを「すべて」と扱わない形に直した。4b の Task 2 の軽微） */
    public function test_every_status_choice_still_works(): void
    {
        $w        = $this->approvalWorld();
        $decided  = $this->decidedContract($w, $this->housingContractType($w));
        $progress = $this->submittedFor($w);
        $viewer   = $this->viewAllUser();
        $ids      = fn (string $status) => Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/', 'GET', ['status' => $status])));

        $this->assertSame([$decided->id], $ids('numbered'));
        $this->assertSame([$progress->id], $ids('progress'));
        $this->assertSame([], $ids('withdrawn'));
        $this->assertEqualsCanonicalizing([$decided->id, $progress->id], $ids('all'));
    }
}
