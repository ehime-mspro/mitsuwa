<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalDecision;
use App\Support\Approval\LedgerFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 決裁台帳の絞り込みの条件の読み取り（段階4 設計書 §5.8・§9 の 9）。読み取れない値は断らずに外し、外した項目の名前を残す。
 */
class LedgerFilterTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private function filter(array $query): LedgerFilter
    {
        return LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query));
    }

    public function test_nothing_given_is_the_default(): void
    {
        $filter = $this->filter([]);

        $this->assertSame(
            [null, null, null, null, null, null, 'numbered', null, null, []],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom, $filter->decidedTo, $filter->status, $filter->decision, $filter->keyword, $filter->ignored]
        );
        $this->assertSame([], $filter->query());
        $this->assertEquals(LedgerFilter::defaults(), $filter);
    }

    public function test_empty_values_are_not_conditions(): void
    {
        $filter = $this->filter(['year' => '', 'q' => '', 'status' => '']);

        $this->assertSame([null, null, 'numbered', [], []], [$filter->year, $filter->keyword, $filter->status, $filter->ignored, $filter->query()]);
    }

    public function test_each_condition_is_read(): void
    {
        $filter = $this->filter([
            'year' => '2026', 'department' => '3', 'type' => '12', 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05',
            'status' => 'all', 'decision' => 'conditional', 'q' => '社用車',
        ]);

        $this->assertSame(
            [2026, 3, 12, '山田', '2026-05-01 00:00 Asia/Tokyo', '2026-10-05 00:00 Asia/Tokyo', 'all', ApprovalDecision::Conditional, '社用車', []],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom?->format('Y-m-d H:i e'), $filter->decidedTo?->format('Y-m-d H:i e'), $filter->status, $filter->decision, $filter->keyword, $filter->ignored]
        );
        $this->assertSame(
            ['year' => 2026, 'department' => 3, 'type' => 12, 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05', 'status' => 'all', 'decision' => 'conditional', 'q' => '社用車'],
            $filter->query()
        );
    }

    public function test_unreadable_values_are_left_out_and_named(): void
    {
        $filter = $this->filter([
            'year' => '26', 'department' => '0', 'type' => '-1', 'applicant' => str_repeat('山', LedgerFilter::APPLICANT_MAX + 1),
            'from' => '2026-02-30', 'to' => '2026/10/05', 'status' => 'draft', 'decision' => 'return', 'q' => str_repeat('車', LedgerFilter::KEYWORD_MAX + 1),
        ]);

        $this->assertSame(
            [null, null, null, null, null, null, 'numbered', null, null],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom, $filter->decidedTo, $filter->status, $filter->decision, $filter->keyword]
        );
        $this->assertSame(['年度', '申請部門', '申請の種類', '申請者', '決裁日（から）', '決裁日（まで）', '状態', '判断', 'キーワード'], $filter->ignored);
        $this->assertSame([], $filter->query());
    }

    public function test_the_longest_values_are_still_read(): void
    {
        $filter = $this->filter(['applicant' => str_repeat('山', LedgerFilter::APPLICANT_MAX), 'q' => str_repeat('車', LedgerFilter::KEYWORD_MAX)]);

        $this->assertSame([LedgerFilter::APPLICANT_MAX, LedgerFilter::KEYWORD_MAX, []], [mb_strlen($filter->applicant), mb_strlen($filter->keyword), $filter->ignored]);
    }

    public function test_arrays_are_left_out_rather_than_breaking_the_page(): void
    {
        $filter = $this->filter(['q' => ['車'], 'year' => ['2026'], 'status' => ['all']]);

        $this->assertSame([null, null, 'numbered', ['年度', '状態', 'キーワード']], [$filter->keyword, $filter->year, $filter->status, $filter->ignored]);
    }

    public function test_broken_text_is_left_out(): void
    {
        // 不正な UTF-8（手で打った URL）。そのままだと語に分けられず、記録の JSON にもできない
        $filter = $this->filter(['q' => "\xFF", 'applicant' => "山\xC3"]);

        $this->assertSame([null, null, ['申請者', 'キーワード']], [$filter->keyword, $filter->applicant, $filter->ignored]);
    }

    public function test_values_outside_the_choices_are_left_out(): void
    {
        $filter = $this->filter(['year' => '1990', 'department' => '99999', 'type' => '7', 'from' => '2026-02-30'])->within([2026, 2025], [1, 2], [7]);

        $this->assertSame([null, null, 7], [$filter->year, $filter->departmentId, $filter->typeId]);
        $this->assertSame(['決裁日（から）', '年度', '申請部門'], $filter->ignored, '読めなかった項目に足す');

        $kept = $this->filter(['year' => '2025', 'department' => '2'])->within([2026, 2025], [1, 2], []);
        $this->assertSame([2025, 2, []], [$kept->year, $kept->departmentId, $kept->ignored]);

        $type = $this->filter(['type' => '8'])->within([], [], [7]);
        $this->assertSame([null, ['申請の種類']], [$type->typeId, $type->ignored]);
    }

    public function test_the_log_keeps_every_key_in_the_same_order(): void
    {
        $this->assertSame(
            ['year' => null, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => null, 'keyword' => null],
            $this->filter([])->toLog()
        );
        $this->assertSame(
            ['year' => 2026, 'department_id' => 3, 'type_id' => 12, 'applicant' => '山田', 'decided_from' => '2026-05-01', 'decided_to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'keyword' => '社用車'],
            $this->filter(['year' => '2026', 'department' => '3', 'type' => '12', 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'q' => '社用車'])->toLog()
        );
    }

    public function test_words_are_split_on_half_and_full_width_spaces(): void
    {
        $this->assertSame(['山田', '太郎', '花子'], LedgerFilter::terms("山田 太郎　\t花子"));
        $this->assertSame([], LedgerFilter::terms(null));
        $this->assertSame(['車'], LedgerFilter::terms('車'));
    }
}
