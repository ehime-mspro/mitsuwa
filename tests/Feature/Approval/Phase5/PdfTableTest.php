<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * PDF の明細表・追加の欄・定型文（要件 9.2・5.6・段階5 設計書 §5.7）。
 *
 * ⚠ 4a の落とし穴（BACKLOG の 4a の節・pdf.blade.php の先頭の注記）を戻さない: 人が打つ文字の入る表には class="wrap"
 *   （無いと空白の無い長い語で表ごと文字が縮む）・表の 1 行＝罫線の 1 行（行の切れ目で次のページへ）。縮んだかは PDF の文字の大きさで見る
 */
class PdfTableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 1 本のテストの中で使い回す種類（種類名は一意） */
    private ?ApprovalType $contract = null;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出した申請（行を差し替えられる） */
    private function submittedContract(array $w, ?array $upper = null, ?array $lower = null, ?string $fixedText = null): ApprovalRequest
    {
        return $this->submittedFor($w, [
            'type_id' => ($this->contract ??= $this->housingContractType($w, ['subject_suffix' => null]))->id, 'subject' => '山田様請負新築工事契約の件', 'body' => '仕様変更によるオプション工事を含む。',
            'amount' => 41700000, 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '佐藤 健一', 'contract_date' => '2026-10-20',
            'fixed_text' => $fixedText ?? '上記の内容に基づき、販売をおこないます。',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => $upper ?? [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('オプション工事', false, 1200000, 850000), $this->row('紹介料', true, 0, 300000)],
                'lower'    => $lower ?? [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
            ],
        ]);
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b#', $pdf);
    }

    /** PDF の中で使われている文字の大きさ（pt。小さい順）。mPDF は表を縮めるとき、その表の文字の大きさを小さくして描く（PdfSheetTest と同じ読み方） */
    private static function fontSizes(string $pdf): array
    {
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
        $sizes = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content !== false && preg_match_all('#/F\d+ ([\d.]+) Tf#', $content, $found)) {
                $sizes = array_merge($sizes, array_map('floatval', $found[1]));
            }
        }
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return $sizes;
    }

    /** 紙面の文字（タグの境目を空白にしてタグを除き、空白を詰めたもの） */
    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('#<style>.*?</style>#s', '', $html))), ENT_QUOTES, 'UTF-8')));
    }

    public function test_the_sheet_carries_the_rows_the_extras_and_the_fixed_text(): void
    {
        $w     = $this->approvalWorld();
        $sheet = PdfSheet::for($w['head'], $this->submittedContract($w));

        $this->assertSame([
            ['kind' => 'row', 'name' => '工事請負金額', 'sale' => '28,500,000円', 'cost' => '22,000,000円', 'profit' => '6,500,000円', 'rate' => '22.8%'],
            ['kind' => 'row', 'name' => 'オプション工事', 'sale' => '1,200,000円', 'cost' => '850,000円', 'profit' => '350,000円', 'rate' => '29.2%'],
            ['kind' => 'row', 'name' => '紹介料', 'sale' => '0円', 'cost' => '300,000円', 'profit' => '-300,000円', 'rate' => '—'],
            ['kind' => 'subtotal', 'name' => '計', 'sale' => '29,700,000円', 'cost' => '23,150,000円', 'profit' => '6,550,000円', 'rate' => '22.1%'],
            ['kind' => 'row', 'name' => '土地契約金額', 'sale' => '12,000,000円', 'cost' => '10,500,000円', 'profit' => '1,500,000円', 'rate' => '12.5%'],
            ['kind' => 'total', 'name' => '合計金額', 'sale' => '41,700,000円', 'cost' => '33,650,000円', 'profit' => '8,050,000円', 'rate' => '19.3%'],
        ], $sheet->amountRows, '名前も金額も無い自由行は載せない');
        $this->assertSame(['tsubo' => ['坪数', '38.5坪'], 'tsubo_price' => ['坪単価', '1,083,000円'], 'staff' => ['担当者', '佐藤 健一'], 'contract_date' => ['契約予定日', '2026/10/20']], $sheet->extras);
        $this->assertSame([['坪数', '38.5坪'], ['坪単価', '1,083,000円']], $sheet->extraPairs(PdfSheet::EXTRAS_BEFORE_FIXED_TEXT));
        $this->assertSame([['担当者', '佐藤 健一'], ['契約予定日', '2026/10/20']], $sheet->extraPairs(PdfSheet::EXTRAS_AFTER_FIXED_TEXT));
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $sheet->fixedText);
        $this->assertSame(['仕様変更によるオプション工事を含む。', ''], $sheet->bodyRows, '補足は紙の 2 行');
        $this->assertSame('41,700,000円', $sheet->amountLabel);
    }

    /** 紙の住宅の様式の「（記）」の並び: 明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足（重点ポイントの欄は出さない。設計書 §5.7） */
    public function test_the_paper_follows_the_housing_form(): void
    {
        $w    = $this->approvalWorld();
        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $this->submittedContract($w))])->render();
        $text = self::text($html);

        $this->assertStringContainsString(
            '（記） 項目 販売金額 工事原価 粗利益金額 粗利率 工事請負金額 28,500,000円 22,000,000円 6,500,000円 22.8%'
            . ' オプション工事 1,200,000円 850,000円 350,000円 29.2% 紹介料 0円 300,000円 -300,000円 —'
            . ' 計 29,700,000円 23,150,000円 6,550,000円 22.1% 土地契約金額 12,000,000円 10,500,000円 1,500,000円 12.5%'
            . ' 合計金額 41,700,000円 33,650,000円 8,050,000円 19.3%'
            . ' 坪数 38.5坪 坪単価 1,083,000円 上記の内容に基づき、販売をおこないます。'
            . ' 担当者 佐藤 健一 契約予定日 2026/10/20 補足 仕様変更によるオプション工事を含む。',
            $text
        );
        $this->assertStringNotContainsString('重点ポイント箇条書', $text);
        // 人が打つ文字の入る表はすべて折り返す（落とし穴 ②）。合計の行は網掛け
        $this->assertSame(0, preg_match('/<table>(?![^<]*<tr>\s*<td class="label" style="width: 18mm;">決裁No)/', $html), 'class="wrap" の無い表がある（決裁No・日付の表を除く）');
        $this->assertSame(2, substr_count($html, '<tr class="sum">'));
        // 明細表の見出しの行は thead（行が多くて次のページへ続いても、ページの頭に見出しが出る。点検の M-8）
        $this->assertMatchesRegularExpression('#<thead>\s*<tr>\s*<td class="label">項目</td>#u', $html);
    }

    public function test_a_points_request_shows_the_extras_and_the_fixed_text_below_the_body(): void
    {
        $w = $this->approvalWorld();
        $w['type']->update(['uses_staff' => true, 'fixed_text' => '本件は社内規程に基づく。']);
        $request = $this->submittedFor($w, ['staff' => '佐藤', 'fixed_text' => '本件は社内規程に基づく。']);

        $sheet = PdfSheet::for($w['head'], $request);
        $this->assertNull($sheet->amountRows);
        $this->assertCount(PdfSheet::MIN_BODY_ROWS, $sheet->bodyRows);

        $text = self::text(view('approvals.requests.pdf', ['sheet' => $sheet])->render());
        $this->assertStringContainsString('重点ポイント箇条書（5W2H） ■ なぜ（目的・理由） ・老朽化のため', $text);
        $this->assertStringContainsString('・老朽化のため 本件は社内規程に基づく。 担当者 佐藤 （添付ファイル 0 件）', $text, '定型文 → 担当者・契約予定日（明細表の種類と同じ並び）');
        $this->assertStringNotContainsString('（記）', $text);
    }

    /** 長い項目名（空白の無い 30 文字）や行の多い明細表でも、文字を縮めずに折り返し・次のページへ続ける（落とし穴 ②・表の 1 行＝罫線の 1 行） */
    public function test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet(): void
    {
        $w     = $this->approvalWorld();
        $plain = self::fontSizes(ApprovalPdf::sheet(PdfSheet::for($w['head'], $this->submittedContract($w))));
        $this->assertContains(10.0, $plain, '文字の大きさを読み取れている（読み取れないと、下の比べが空振りする）');

        $long = $this->submittedContract($w, [$this->row(substr('https://example.com/' . str_repeat('abcdefghij', 3), 0, 30), false, 1000, 500)]);
        $this->assertSame($plain, self::fontSizes(ApprovalPdf::sheet(PdfSheet::for($w['head'], $long))), '空白の無い長い項目名は折り返す（表の文字は縮まない）');

        $rows = fn (string $label) => array_map(fn (int $i) => $this->row("{$label} {$i}", false, 1000000 * $i, 900000 * $i), range(1, 30));
        $many = $this->submittedContract($w, $rows('追加工事'), $rows('土地'));
        $pdf  = ApprovalPdf::sheet(PdfSheet::for($w['head'], $many));
        $this->assertSame($plain, self::fontSizes($pdf), '60 行の明細表でも文字を縮めない');
        $this->assertGreaterThanOrEqual(2, self::pageCount($pdf), '行が多ければ次のページへ続く');
    }

    /**
     * 追加の欄と定型文の表（1 行だけの表）がページの下の端に来ても、文字を縮めない（点検の I-1。mPDF は 1 行の表を、
     * 残りの高さに収まるまで縮めて同じページに置く。autosize="1" を付けると次のページへ送る）。19 行の明細表・5 行の定型文で確かめる
     */
    public function test_the_extras_and_the_fixed_text_do_not_shrink_at_the_bottom_of_a_page(): void
    {
        $w     = $this->approvalWorld();
        $plain = self::fontSizes(ApprovalPdf::sheet(PdfSheet::for($w['head'], $this->submittedContract($w))));

        $rows  = array_map(fn (int $i) => $this->row("行 {$i}", false, 1000 * $i, 900 * $i), range(1, 19));
        $fixed = implode("\n", array_map(fn (int $i) => "定型文 {$i} 行目", range(1, 5)));
        $pdf   = ApprovalPdf::sheet(PdfSheet::for($w['head'], $this->submittedContract($w, $rows, null, $fixed)));
        $this->assertSame($plain, self::fontSizes($pdf), '下の端に来た追加の欄と定型文の表を縮めない');
    }

    public function test_the_sheet_escapes_the_table_the_extras_and_the_fixed_text(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedContract($w, [$this->row('<b>項目</b>', false, 1000, null)]);
        $request->update(['fixed_text' => '<i>定型</i>']);
        DB::table('approval_revisions')->where('request_id', $request->id)->update(['snapshot' => json_encode(array_merge(
            $request->revisions()->sole()->snapshot,
            ['fixed_text' => "<i>定型</i>\n2 行目", 'extras' => ['staff' => '<u>担当</u>']]
        ), JSON_UNESCAPED_UNICODE)]);

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $request->fresh())])->render();
        $this->assertStringContainsString(e('<b>項目</b>'), $html);
        $this->assertStringContainsString(e('<u>担当</u>'), $html);
        $this->assertStringContainsString(e('<i>定型</i>') . '<br />' . "\n" . '2 行目', $html, '定型文は改行を保って逃がす');
        $this->assertStringNotContainsString('<b>項目</b>', $html);
    }
}
