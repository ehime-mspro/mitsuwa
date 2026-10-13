<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImporter;
use App\Support\Approval\PaperImportException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\TestCase;

/**
 * 紙の決裁の番号と採番（段階5 設計書 D7・§5.11）。今年度の紙の番号を足すと次の番号が進み（戻さない）、開始番号は紙の番号以下にできない。
 * 過去の年度の紙の番号は採番に関わらない。取り込みの確定は、確認のあとで番号がふさがっていたら何も書かずに断る。
 */
class PaperNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w = $this->approvalWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_paper_number_of_this_year_moves_the_next_number_forward_only(): void
    {
        $dept = $this->w['dept'];

        $this->assertSame(35, ApprovalNumber::advancePast($dept, 2026, 34));
        $this->assertSame(35, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(0, ApprovalNumber::currentState($dept)['last_issued'], '使った番号（システムの採番）は変えない');

        $this->assertSame(35, ApprovalNumber::advancePast($dept, 2026, 10), '小さい番号では戻さない');
        ApprovalNumber::setNext($dept, 50);
        $this->assertSame(50, ApprovalNumber::advancePast($dept, 2026, 34));

        $this->assertSame('R8-J-050', ApprovalNumber::issue($dept, now())['number']);
    }

    public function test_a_paper_number_of_a_past_year_does_not_touch_the_numbering(): void
    {
        $this->assertNull(ApprovalNumber::advancePast($this->w['dept'], 2025, 90));
        $this->assertSame(0, ApprovalNumberSequence::count(), '過去の年度の連番の行を作らない');
    }

    /** 開始番号は今年度の紙の番号以下にできない（過去の年度の紙は関わらない） */
    public function test_the_start_number_cannot_go_under_this_years_paper_numbers(): void
    {
        $dept = $this->w['dept'];
        $this->paperRecord($dept, ['fiscal_year' => 2025, 'seq' => 90]);
        ApprovalNumber::setNext($dept, 2);

        $this->paperRecord($dept, ['fiscal_year' => 2026, 'seq' => 34]);
        try {
            ApprovalNumber::setNext($dept, 34);
            $this->fail('紙の番号へ戻せた');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('今年度は紙の決裁で R8-J-034 まで使っています。35 以上を入れてください。', $e->getMessage());
        }

        ApprovalNumber::setNext($dept, 35);
        $this->assertSame(35, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(34, ApprovalNumber::lastPaperSeq($dept, 2026));
        $this->assertSame(0, ApprovalNumber::lastPaperSeq($this->w['reviewDept'], 2026));
    }

    /** 部門の管理の編集のモーダルの案内は、今年度の紙の番号のほうが大きければそちらを出す */
    public function test_the_organization_screen_names_the_last_paper_number(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2026, 'seq' => 34]);
        $this->paperRecord($this->w['reviewDept'], ['fiscal_year' => 2025, 'seq' => 7]);

        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
        preg_match_all("/openDepartmentEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $hints = [];
        foreach ($m[1] as $inner) {
            $row               = json_decode(json_decode('"' . $inner . '"'), true);
            $hints[$row['id']] = $row['number_hint'];
        }

        $this->assertSame('今年度（R8）は紙の決裁で R8-J-034 まで使っています。', $hints[$this->w['dept']->id]);
        $this->assertSame('今年度（R8）はまだ番号を使っていません。', $hints[$this->w['reviewDept']->id], '過去の年度の紙は案内に出さない');
    }

    // --- 取り込みの確定の歯止め（PaperImporter） ---

    private function analysis(): array
    {
        return PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [
            [1, '8.05.07', '甲野', '見本町　用地購入'],
            [2, '8.05.18', '乙山', '見本邸新築工事契約'],
        ]]), 2026);
    }

    /** 確認のあとで社長の判断が同じ番号を付けていたら、何も書かずに断る（ロックのあとで確かめる） */
    public function test_a_system_number_taken_after_the_confirmation_stops_the_import(): void
    {
        $analysis = $this->analysis();
        $draft    = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-002', 'number_department_id' => $this->w['dept']->id]);

        try {
            PaperImporter::apply($analysis, 2026, '08年度.xls', $this->approvalAdmin());
            $this->fail('ふさがった番号で取り込めた');
        } catch (PaperImportException $e) {
            $this->assertSame('確認の画面を出したあとで、システムの申請が R8-J-002 を使いました。もう一度アップロードして確かめてください。', $e->getMessage());
        }

        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept'])['next'], '次の番号も戻る');
        $this->assertSame(1, ApprovalRequest::whereNotNull('number')->count());
    }

    /** 確認のあとでほかの管理者が同じ番号の紙の決裁を登録していたら、一意の索引で止まり、何も残さない */
    public function test_a_paper_number_taken_after_the_confirmation_stops_the_import(): void
    {
        $analysis = $this->analysis();
        $this->paperRecord($this->w['dept'], ['seq' => 2]);

        try {
            PaperImporter::apply($analysis, 2026, '08年度.xls', $this->approvalAdmin());
            $this->fail('重なった番号で取り込めた');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame([1, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept'])['next']);
    }
}
