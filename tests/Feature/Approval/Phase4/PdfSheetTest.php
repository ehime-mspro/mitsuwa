<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use App\Support\Approval\StampSvg;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁申請書の PDF の紙面（要件 9.2・段階4 設計書 §5.6・D4・D14〜D17） */
class PdfSheetTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:12:00', 'UTC'));   // 日本時間 10/5 1:12
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 部門長・審査を通して社長の決裁待ちまで */
    private function toPresident(array $w, array $attributes = [], string $headComment = '急ぎでお願いします', string $reviewComment = '見積を 2 社取ってください'): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, $headComment);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, $reviewComment);

        return $r->fresh();
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b#', $pdf);
    }

    /** PDF の中で使われている文字の大きさ（pt。小さい順）。mPDF は表を縮めるとき、その表の文字の大きさを小さくして描く */
    private static function fontSizes(string $pdf): array
    {
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
        $sizes = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);   // 文字の流れだけ圧縮されている（フォントなどは別の形）
            if ($content !== false && preg_match_all('#/F\d+ ([\d.]+) Tf#', $content, $found)) {
                $sizes = array_merge($sizes, array_map('floatval', $found[1]));
            }
        }
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return $sizes;
    }

    /** 社長が可と決裁した申請の紙面（コメント・件名・添付のファイル名を変えられる。申請者が開く） */
    private function decidedSheet(array $w, array $attributes = [], string $headComment = '急ぎでお願いします', string $reviewComment = '見積を 2 社取ってください', string $presidentComment = '了承', ?string $attachmentName = null): PdfSheet
    {
        $r = $this->toPresident($w, $attributes, $headComment, $reviewComment);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, $presidentComment);

        if ($attachmentName !== null) {
            $this->attach($r, $w, $attachmentName);
        }

        return PdfSheet::for($w['applicant'], $r->fresh());
    }

    /** 申請に添付を 1 つ足す（申請者が足した形） */
    private function attach(ApprovalRequest $r, array $w, string $name): void
    {
        ApprovalAttachment::create(['request_id' => $r->id, 'original_name' => $name, 'stored_path' => "approvals/{$r->id}/x.pdf",
            'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $w['applicant']->id, 'added_round' => $r->round + 1]);
    }

    public function test_a_decided_request_has_the_number_the_marks_and_the_three_stamps(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);

        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame('決裁済み（可）', $sheet->statusLabel);
        $this->assertSame(1, $sheet->round);
        $this->assertSame('R8-J-001', $sheet->number);
        $this->assertSame(['2026年10月5日', '2026年10月5日', '2026年10月5日'], [$sheet->decidedOn, $sheet->submittedOn, $sheet->receivedOn], '日本時間の日付');
        $this->assertSame(['可', '保留'], [$sheet->decisionMark, $sheet->reviewMark]);
        $this->assertSame(['社長', '住宅', '総務'], [$sheet->presidentStamp?->label, $sheet->headStamp?->label, $sheet->reviewStamp?->label]);
        $this->assertSame(['急ぎでお願いします', '見積を 2 社取ってください'], [$sheet->headComment, $sheet->reviewComment]);
        $this->assertSame(['住宅事業部', '申請 花子', '総務部'], [$sheet->departmentName, $sheet->applicantName, $sheet->reviewDepartmentName]);
        $this->assertSame(['社用車の購入', '2,850,000円', '2026年10月'], [$sheet->subject, $sheet->amountLabel, $sheet->schedule]);
        $this->assertSame(['2026/10/05 01:12', '申請 花子'], [$sheet->outputAt, $sheet->outputBy], '出力の日時は日本時間・出力者は開いた人');
        $this->assertSame('決裁申請書_R8-J-001.pdf', $sheet->fileName);
        $this->assertFalse($sheet->headSkipped);
    }

    public function test_the_issue_date_and_the_receipt_date_come_from_different_moments(): void
    {
        $w = $this->approvalWorld();
        Carbon::setTestNow(Carbon::parse('2026-10-02 23:00:00', 'UTC'));   // 日本時間 10/3 8:00 に提出
        $r = $this->submittedFor($w);
        Carbon::setTestNow(Carbon::parse('2026-10-04 15:30:00', 'UTC'));   // 日本時間 10/5 0:30 に部門長が承認＝審査部門に届く
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame(['2026年10月3日', '2026年10月5日'], [$sheet->submittedOn, $sheet->receivedOn], '発信日は提出・受付日は審査部門に届いた日（日本時間）');
    }

    public function test_before_the_decision_the_number_date_and_marks_are_blank(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $sheet = PdfSheet::for($w['applicant'], $r);

        $this->assertSame('部門長確認中', $sheet->statusLabel);
        $this->assertSame([null, null, null, null], [$sheet->number, $sheet->decidedOn, $sheet->receivedOn, $sheet->decisionMark]);
        $this->assertSame([null, null, null], [$sheet->presidentStamp, $sheet->headStamp, $sheet->reviewStamp]);
        $this->assertSame('決裁申請書_申請' . $r->id . '.pdf', $sheet->fileName, '番号の前は申請の番号');
    }

    public function test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '月次で報告すること');

        $before = PdfSheet::for($w['applicant'], $r->fresh());
        $this->assertSame(['条可', '月次で報告すること', null], [$before->decisionMark, $before->presidentComment, $before->conditionConfirmedAt]);

        Carbon::setTestNow(Carbon::parse('2026-10-05 02:30:00', 'UTC'));
        $this->workflow->confirmCondition($r->fresh(), $w['applicant'], $r->fresh()->lock_version, null);

        $this->assertSame('2026/10/05 11:30', PdfSheet::for($w['applicant'], $r->fresh())->conditionConfirmedAt);
    }

    public function test_only_the_current_round_is_shown_after_a_return(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額を見直してください');

        $returned = PdfSheet::for($w['reviewer'], $r->fresh());
        $this->assertSame(['差戻し中', '差戻', '金額を見直してください'], [$returned->statusLabel, $returned->decisionMark, $returned->presidentComment], '差戻しの回の社長の判断と印');
        $this->assertNotNull($returned->presidentStamp);

        $this->workflow->submit($r->fresh(), $w['applicant']);
        $second = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame(2, $second->round);
        $this->assertSame([null, null, null, null], [$second->decisionMark, $second->presidentStamp, $second->headStamp, $second->headComment], '前の回の判断と印は載せない');
    }

    public function test_others_see_the_last_submitted_content_while_the_applicant_edits(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '提出した件名']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $r->fresh()->update(['subject' => '直しかけの件名']);

        $this->assertSame('提出した件名', PdfSheet::for($w['head'], $r->fresh())->subject);
        $this->assertSame('直しかけの件名', PdfSheet::for($w['applicant'], $r->fresh())->subject);
    }

    public function test_a_skipped_head_step_is_marked_as_skipped(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $sheet = PdfSheet::for($w['head'], $r);

        $this->assertTrue($sheet->headSkipped);
        $this->assertNull($sheet->headStamp);
    }

    public function test_the_body_is_split_into_ruled_rows(): void
    {
        $this->assertSame(array_pad(['■ なぜ', '・老朽化のため'], PdfSheet::MIN_BODY_ROWS, ''), PdfSheet::bodyRows("■ なぜ\r\n・老朽化のため\n\n"), '改行で分け、末尾の空行は落とし、紙の行数まで空の行で埋める');
        $this->assertSame(array_fill(0, PdfSheet::MIN_BODY_ROWS, ''), PdfSheet::bodyRows(null));

        $long = PdfSheet::bodyRows(str_repeat('あ', PdfSheet::BODY_ROW_CHARS * 2 + 5));
        $this->assertSame([PdfSheet::BODY_ROW_CHARS, PdfSheet::BODY_ROW_CHARS, 5], array_map('mb_strlen', array_slice($long, 0, 3)), '長い行は分ける（表の 1 行がページより高くならないように）');

        $many = PdfSheet::bodyRows(implode("\n", range(1, 30)));
        $this->assertCount(30, $many, '紙の行数より多ければそのまま');
        $this->assertCount(12, PdfSheet::bodyRows(implode("\n", range(1, 12)) . "\n\n\n"), '末尾の空行は罫線にしない');
    }

    public function test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, ['body' => str_repeat("・決裁申請システムの本文の試しです。\n", 120)]);

        $pdf = ApprovalPdf::sheet(PdfSheet::for($w['applicant'], $r));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('IPAexGothic', $pdf, 'ゴシックを埋め込む');
        $this->assertStringContainsString('IPAexMincho', $pdf, '明朝を埋め込む（題・枠・印）');
        $this->assertGreaterThanOrEqual(4, self::pageCount($pdf), '本文が長ければ次のページへ続く（縮めない。120 行は 10pt で 4 ページ）');
    }

    public function test_a_long_word_without_spaces_in_the_body_does_not_shrink_the_body(): void
    {
        $w       = $this->approvalWorld();
        $line    = "・決裁申請システムの本文の試しです。\n";
        $url     = 'https://example.com/' . str_repeat('abcdefghij', 18);   // 空白の無い 200 文字
        $plain   = $this->toPresident($w, ['body' => str_repeat($line, 120)]);
        $withUrl = $this->toPresident($w, ['body' => str_repeat($line, 60) . $url . "\n" . str_repeat($line, 60)]);

        $pages = self::pageCount(ApprovalPdf::sheet(PdfSheet::for($w['applicant'], $plain)));

        $this->assertGreaterThanOrEqual($pages, self::pageCount(ApprovalPdf::sheet(PdfSheet::for($w['applicant'], $withUrl))), '長い語はセルの中で折り返し、本文の表全体を縮めない（縮むとページが減る）');
    }

    public function test_a_long_word_without_spaces_does_not_shrink_the_subject_the_comments_or_the_attachments(): void
    {
        $w     = $this->approvalWorld();
        $word  = fn (int $length): string => substr('https://example.com/' . str_repeat('abcdefghij', (int) ceil($length / 10)), 0, $length);   // 空白の無い長い語（共有リンクなど）
        $plain = self::fontSizes(ApprovalPdf::sheet($this->decidedSheet($w)));

        $this->assertContains(10.0, $plain, '文字の大きさを読み取れている（読み取れないと、下の比べが空振りする）');

        $cases = [
            '社長のコメント（70 文字）'        => ['presidentComment' => $word(70)],
            '社長のコメント（150 文字）'       => ['presidentComment' => $word(150)],
            '部門長のコメント'                 => ['headComment' => $word(150)],
            '審査のコメント'                   => ['reviewComment' => $word(150)],
            '件名（100 文字まで）'             => ['attributes' => ['subject' => $word(100)]],
            '添付のファイル名（255 文字まで）' => ['attachmentName' => substr($word(255), 0, 251) . '.pdf'],
        ];

        foreach ($cases as $label => $arguments) {
            $sheet = $this->decidedSheet($w, ...$arguments);

            if (isset($arguments['attachmentName'])) {
                $this->assertSame([$arguments['attachmentName']], $sheet->attachmentNames, '添付は紙面に載っている（載らないと、この確かめが空振りする）');
            }
            $this->assertSame($plain, self::fontSizes(ApprovalPdf::sheet($sheet)), "{$label}に空白の無い長い語があっても、その表の文字は縮まない（折り返す）");
        }
    }

    public function test_the_sheet_draws_the_marks_the_stamps_and_the_footer(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '月次で報告すること');
        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $html = view('approvals.requests.pdf', ['sheet' => $sheet])->render();

        // 判断したものだけ朱の枠（決裁は条可・審査は保留）
        $this->assertStringContainsString('<td class="mark on">条可</td>', $html);
        $this->assertStringContainsString('<td class="mark">可</td>', $html);
        $this->assertStringContainsString('<td class="mark on">保留</td>', $html);
        $this->assertSame(2, substr_count($html, 'class="mark on"'));
        $this->assertStringContainsString('td.mark.on { border: 1.2pt solid ' . StampSvg::COLOR . ';', $html, '朱の枠は td.mark の黒の枠より強い選び方で書く（.on だけだと黒の細い枠のまま）');
        // 3 つの印と、条可の条件
        foreach (['社長 R8.10.5 社長 の印', '住宅 R8.10.5 部門 の印', '総務 R8.10.5 審査 の印'] as $aria) {
            $this->assertStringContainsString('aria-label="' . $aria . '"', $html);
        }
        $this->assertStringContainsString('決裁者コメント（条件）', $html);
        $this->assertStringContainsString('月次で報告すること', $html);
        $beforeStamp = strstr($html, 'aria-label="社長 R8.10.5 社長 の印"', true);
        $this->assertStringNotContainsString('</table>', substr($beforeStamp, strrpos($beforeStamp, '<td')), '社長の印のセルに判断の欄の表を入れ子にしない（mPDF がセルの高さを少なく見積もり、印が枠からはみ出す）');
        $this->assertSame(PdfSheet::MIN_BODY_ROWS, substr_count($html, '<td class="line">'), '本文は罫線の行ごと');

        $footer = view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render();
        $this->assertStringContainsString('決裁申請システムから出力 2026/10/05 01:12（出力者: 申請 花子）', $footer);
        $this->assertStringContainsString('{PAGENO} / {nbpg}', $footer);
    }

    public function test_the_sheet_says_the_head_step_was_skipped(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $r)])->render();

        $this->assertStringContainsString('申請者が部門長のため省略', $html);
        $this->assertStringNotContainsString('aria-label=', $html, '印はまだ無い');
    }

    public function test_the_sheet_escapes_what_people_typed(): void
    {
        $w = $this->approvalWorld();
        $w['head']->update(['name' => '<b>部門</b>長']);
        // 申請部門の名前・申請者の氏名・添付のファイル名も人が打てる文字（申請者が開くので、今の値がそのまま紙面に出る）
        $w['dept']->update(['name' => '<b>部</b>']);
        $w['applicant']->update(['name' => '<i>山田</i>']);
        $r = $this->toPresident($w, ['subject' => '<img src=x onerror=alert(1)>', 'body' => "<script>alert(2)</script>"], "<u>部門長</u>の意見\n2 行目", '<u>審査</u>の意見');
        $this->attach($r, $w, '<img src=y>.pdf');
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, "<i>見直し</i>\n2 行目");

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['applicant'], $r->fresh())])->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringNotContainsString('<b>部門</b>', $html);
        $this->assertStringNotContainsString('<i>見直し</i>', $html);
        $this->assertStringNotContainsString('<u>部門長</u>', $html);
        $this->assertStringNotContainsString('<u>審査</u>', $html);
        $this->assertStringNotContainsString('<img src=y', $html, '添付のファイル名');
        $this->assertStringNotContainsString('<b>部</b>', $html, '申請部門の名前');
        $this->assertStringNotContainsString('<i>山田</i>', $html, '申請者の氏名');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;見直し&lt;/i&gt;<br />', $html, 'コメントはエスケープしてから改行を <br> にする');
        $this->assertStringContainsString('&lt;u&gt;部門長&lt;/u&gt;の意見<br />', $html, '部門長のコメントも');
        $this->assertStringContainsString('&lt;u&gt;審査&lt;/u&gt;の意見', $html, '審査のコメントも');
        $this->assertStringContainsString('&lt;img src=y&gt;.pdf', $html, '添付のファイル名も（mPDF は http・file の読み込みを許すので、外れると紙面がサーバーのファイルを読みに行く）');
        $this->assertStringContainsString('&lt;b&gt;部&lt;/b&gt;', $html, '申請部門の名前も');
        $this->assertStringContainsString('&lt;i&gt;山田&lt;/i&gt;', $html, '申請者の氏名も');
    }
}
