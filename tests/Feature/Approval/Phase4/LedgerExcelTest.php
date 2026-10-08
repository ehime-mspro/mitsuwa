<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 決裁台帳の Excel と出力の記録（要件 10・14.2・段階4 設計書 §5.9・§5.10・D23〜D25）。
 *
 * ⚠ 出した xlsx を PhpSpreadsheet で読み戻して、列・型・書式を見る。式にしないことは、セルの型と、シートの XML に `<f>`
 *   （式）が無いことの両方で見る。
 */
class LedgerExcelTest extends TestCase
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

    /** 審査が保留の意見を付け、社長が条可と決裁した申請 */
    private function conditional(array $w, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, '見積を 2 社取ってください');
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Conditional, '納期を確かめること');

        return $r->fresh();
    }

    private function download(User $viewer, array $query = []): TestResponse
    {
        return $this->actingAs($viewer)->get(route('approvals.ledger.excel', $query));
    }

    /**
     * 応答の xlsx をファイルに書いて読み戻す（読み戻しも、ZIP の中の XML も見る）。
     * ⚠ tempnam() が作る拡張子の無い空のファイルも消す（.xlsx を付けた名前に書くので、元の名前のファイルが残っていた。4b の Task 4 の軽微）
     */
    private function sheet(TestResponse $response): Worksheet
    {
        $base = tempnam(sys_get_temp_dir(), 'ledger');
        $path = $base . '.xlsx';
        file_put_contents($path, $response->getContent());
        // ⚠ 2 つの文に分ける（`@unlink($path) && @unlink($base)` は前が失敗すると後ろを消さない。点検の T-8）
        $this->beforeApplicationDestroyed(function () use ($path, $base): void {
            @unlink($path);
            @unlink($base);
        });

        return IOFactory::load($path)->getActiveSheet();
    }

    private function sheetXml(TestResponse $response): string
    {
        $base = tempnam(sys_get_temp_dir(), 'ledger');
        @unlink($base);
        $path = $base . '.xlsx';
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    public function test_the_columns_and_the_values_of_a_row(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['related_numbers' => ['R7-J-003', 'R7-J-010']]);

        $response = $this->download($this->viewAllUser())->assertOk();
        $sheet    = $this->sheet($response);

        // 段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した（要件 10 の並び。5W2H の種類では空。Phase5/LedgerExcelTableTest が中身を見る）
        $this->assertSame(
            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '状態'],
            $sheet->rangeToArray('A1:S1')[0]
        );
        $this->assertSame(
            ['R8-J-001', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', '2026年10月', 'R7-J-003・R7-J-010', '保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'],
            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'L', 'N', 'P', 'Q', 'R', 'S'])
        );
        $this->assertSame([null, null, null, null], array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['I', 'J', 'K', 'M']), '5W2H の種類では明細表と契約予定日の列は空');
        $this->assertSame(2, $sheet->getHighestRow(), '1 件なので見出しと 1 行');
    }

    public function test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);   // 決裁と提出は UTC の 10/4 16:12 ＝ 日本時間 10/5

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        foreach (['B2', 'O2'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "{$cell} が数（日付）でない");
            $this->assertSame('2026-10-05 00:00:00', Date::excelToDateTimeObject($sheet->getCell($cell)->getValue())->format('Y-m-d H:i:s'), "{$cell} が日本の暦の日でない（時刻を入れない）");
            $this->assertSame('yyyy/mm/dd', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('H2')->getDataType());
        $this->assertEquals(2850000, $sheet->getCell('H2')->getValue());
        $this->assertSame('#,##0', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        $w = $this->approvalWorld();
        $w['applicant']->update(['name' => '+81 花子']);
        $this->conditional($w, ['subject' => '=HYPERLINK("https://example.com","開く")', 'schedule' => '@SUM(A1)', 'related_numbers' => []]);

        $response = $this->download($this->viewAllUser())->assertOk();
        $sheet    = $this->sheet($response);

        foreach (['D2' => '=HYPERLINK("https://example.com","開く")', 'G2' => '+81 花子', 'L2' => '@SUM(A1)'] as $cell => $text) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), "{$cell} が文字でない");
            $this->assertSame($text, $sheet->getCell($cell)->getValue());
        }
        $this->assertStringNotContainsString('<f>', $this->sheetXml($response), 'シートに式が入っている');
    }

    public function test_control_characters_do_not_break_the_file(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['subject' => "縦タブ\x0B入りの件名"]);

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame("縦タブ\x0B入りの件名", $sheet->getCell('D2')->getValue());
    }

    public function test_the_heading_is_frozen_and_has_filter_buttons(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);
        $this->conditional($w);

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:S3', $sheet->getAutoFilter()->getRange());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('決裁台帳', $sheet->getTitle());
    }

    public function test_the_rows_are_the_ledger_with_the_same_conditions_and_order(): void
    {
        $w = $this->approvalWorld();
        $first  = $this->conditional($w, ['subject' => '社用車の購入']);
        $second = $this->conditional($w, ['subject' => '社用車の修理']);
        $this->conditional($w, ['subject' => '机の購入']);
        $this->submittedFor($w, ['subject' => '社用車の売却']);   // 進行中（既定の状態では出ない）

        $sheet = $this->sheet($this->download($this->viewAllUser(), ['q' => '社用車'])->assertOk());

        // 同じ日の決裁は決裁No の順（D22）
        $this->assertSame([[$first->number, '社用車の購入'], [$second->number, '社用車の修理']], [
            [$sheet->getCell('A2')->getValue(), $sheet->getCell('D2')->getValue()],
            [$sheet->getCell('A3')->getValue(), $sheet->getCell('D3')->getValue()],
        ]);
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame(1, $this->sheet($this->download($this->baseUser()))->getHighestRow(), '関わっていない人には見出しだけ');
    }

    public function test_others_get_what_was_last_submitted(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '出した件名']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '直しかけ']);

        $this->assertSame('出した件名', $this->sheet($this->download($w['head'], ['status' => 'progress']))->getCell('D2')->getValue());
        $this->assertSame('直しかけ', $this->sheet($this->download($w['applicant'], ['status' => 'progress']))->getCell('D2')->getValue());
    }

    /** 行は 200 件ずつ読む。200 件を超えても全件を書く（状態の列を書いた控えの写しで件数だけ増やす） */
    public function test_more_than_one_batch_of_rows_is_written(): void
    {
        $w        = $this->approvalWorld();
        $template = $this->conditional($w);
        $request  = (array) DB::table('approval_requests')->where('id', $template->id)->first();
        $revision = (array) DB::table('approval_revisions')->where('request_id', $template->id)->first();
        foreach (range(2, 205) as $seq) {
            $id = DB::table('approval_requests')->insertGetId(array_merge($request, ['id' => null, 'number' => sprintf('R8-J-%03d', $seq), 'number_seq' => $seq]));
            DB::table('approval_revisions')->insert(array_merge($revision, ['id' => null, 'request_id' => $id]));
        }

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame(206, $sheet->getHighestRow(), '見出しと 205 件');
        $this->assertSame('R8-J-205', $sheet->getCell('A206')->getValue());
    }

    public function test_the_file_name_and_the_headers(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        $response = $this->download($this->viewAllUser())->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(
            "attachment; filename=ledger_2026-10-05.xlsx; filename*=utf-8''" . rawurlencode('決裁台帳_2026-10-05.xlsx'),
            $response->headers->get('Content-Disposition'),
            '日本の今日の日付で、日本語の名前と ASCII の代わりの名前'
        );
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent(), 'xlsx（ZIP）でない');
    }

    public function test_each_download_is_recorded_with_the_conditions_and_the_count(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['subject' => '社用車の購入']);
        $this->conditional($w, ['subject' => '社用車の修理']);
        $viewer = $this->viewAllUser();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->download($viewer, ['q' => '社用車', 'decision' => 'conditional', 'year' => '2026'])->assertOk();

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(
            [null, null, $viewer->id, 'excel', 2, '203.0.113.9'],
            [$log->request_id, $log->attachment_id, (int) $log->user_id, $log->kind, $log->fresh()->request_count, $log->ip_address]
        );
        // MySQL の JSON はキーを並べ替えて返す（RequestSnapshot の注意）。並びに頼らずに比べる
        $expected = ['year' => 2026, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => 'conditional', 'keyword' => '社用車'];
        $kept     = $log->fresh()->filters;
        ksort($expected);
        ksort($kept);
        $this->assertSame($expected, $kept);
    }

    public function test_too_many_requests_are_not_made_and_not_recorded(): void
    {
        $w = $this->approvalWorld();
        foreach (range(1, 3) as $i) {
            $this->conditional($w, ['subject' => "社用車 {$i}"]);
        }
        config(['approval.ledger.excel_limit' => 2]);

        $this->download($this->viewAllUser(), ['q' => '社用車'])
            ->assertRedirect(route('approvals.ledger.index', ['q' => '社用車']))
            ->assertSessionHas('error', '絞り込んでから出してください（Excel に出せるのは 2 件までです。いまは 3 件）。');
        $this->assertSame(0, ApprovalDownloadLog::count(), '出さなかったときは記録しない');

        config(['approval.ledger.excel_limit' => 3]);
        $this->download($this->viewAllUser(), ['q' => '社用車'])->assertOk();
        $this->assertSame(3, ApprovalDownloadLog::sole()->fresh()->request_count, '上限ちょうどなら出す');
    }

    public function test_conditions_outside_the_choices_are_left_out_as_on_the_ledger(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        $sheet = $this->sheet($this->download($this->viewAllUser(), ['department' => '99999'])->assertOk());

        $this->assertSame(2, $sheet->getHighestRow(), '台帳の画面と同じく、選択肢に無い部門は外して出す');
        $this->assertNull(ApprovalDownloadLog::sole()->fresh()->filters['department_id']);
    }

    public function test_the_default_limit_is_a_thousand(): void
    {
        // 入力の上限の中身で測って決めた数（config/approval.php の注記・計画 §0.5）。変えるときは測り直す
        $this->assertSame(1000, config('approval.ledger.excel_limit'));
    }

    public function test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        // 手で打った URL の壊れた文字（不正な UTF-8）。外して作り、記録の条件（JSON）にも入れない
        $this->download($this->viewAllUser(), ['q' => "\xFF"])->assertOk();

        $this->assertNull(ApprovalDownloadLog::sole()->fresh()->filters['keyword']);
    }

    public function test_the_ledger_offers_the_excel_only_within_the_limit(): void
    {
        $w = $this->approvalWorld();
        $viewer = $this->viewAllUser();
        $link = 'href="' . e(route('approvals.ledger.excel', ['q' => '社用車'])) . '"';

        $empty = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Excel に出力', $empty, '0 件なら出さない');

        $this->conditional($w, ['subject' => '社用車 1']);
        $this->conditional($w, ['subject' => '社用車 2']);
        config(['approval.ledger.excel_limit' => 2]);
        $within = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringContainsString($link, $within, '表と同じ条件で出す');

        config(['approval.ledger.excel_limit' => 1]);
        $over = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringNotContainsString($link, $over);
        $this->assertStringContainsString('Excel に出せるのは 1 件までです。絞り込んでください。', $over);
    }

    public function test_before_launch_it_is_not_open(): void
    {
        ApprovalSetting::current()->update(['launched_at' => null]);
        $w = $this->approvalWorld();

        $this->download($w['applicant'])->assertRedirect(route('approvals.home'));
        $this->assertSame(0, ApprovalDownloadLog::count());
    }
}
