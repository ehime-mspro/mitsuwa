<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImportException;
use App\Support\Approval\PaperLedgerFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\TestCase;

/**
 * 紙の決裁台帳の読み取りと、取り込みの中身（要件 11.1〜11.4・段階5 設計書 §5.9・D6・D7・D20）。
 *
 * ⚠ 台帳は作りもの（BuildsPaperLedgers。本物の台帳はリポジトリに入れない＝D24）。本物で気をつける点（2 段組み・見出しの繰り返し・
 *   ＮＯ だけの行・和暦のドット・.xls・金額の控えの式と結び付け）はどれも作りものに入れてある。
 */
class PaperImportAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;

    private array $w;

    private ApprovalDepartment $small;

    private ApprovalDepartment $estate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-04-20 03:00:00', 'UTC'));   // R8 年度の終わり近く（1〜4 月の日付も今日より前）
        $this->w      = $this->approvalWorld();                                 // 住宅事業部 J（5 月始まり）
        $this->small  = $this->approvalDepartment($this->w['company'], ['name' => '住宅（少額・追加工事）', 'code' => 'JB']);
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 左右 2 段・2 つ目の塊・ＮＯ だけの行・見出しの繰り返しを読み、.xls でも .xlsx でも同じ中身になる */
    public function test_the_two_sided_register_is_read(): void
    {
        $sheets = [
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.05.18', '乙山', '見本邸新築工事契約'],
                [30, '8.06.30', '丙川', '左の段の最後'],
                [31, '8.07.01', '丁田', '右の段の最初'],
                [61, '8.08.03', '甲野', '2 つ目の塊の左'],
                [120, '9.01.15', '乙山', '2 つ目の塊の右の最後'],
            ],
            'メモ' => [],
        ];

        foreach (['Xls', 'Xlsx'] as $format) {
            $a = PaperImportAnalysis::analyze($this->paperLedger($sheets, [], $format), 2026);

            $this->assertSame([], $a['rowErrors'], $format);
            $this->assertSame(['R8-J-001', 'R8-J-002', 'R8-J-030', 'R8-J-031', 'R8-J-061', 'R8-J-120'], array_column($a['records'], 'number'), "{$format}: シートの順・ＮＯ の順に並べる");
            $first = $a['records'][0];
            $this->assertSame([$this->w['dept']->id, '住宅事業部', 2026, 1, '2026-05-07', '甲野', '見本町　用地購入', null, null, null, 'Ｊ・住宅 5 行目・左（ＮＯ 1）'], [
                $first['department_id'], $first['department'], $first['fiscal_year'], $first['seq'], $first['decided_on'], $first['applicant_name'], $first['subject'],
                $first['amount'], $first['cost'], $first['profit'], $first['where'],
            ]);
            $this->assertSame('2027-01-15', $a['records'][5]['decided_on'], '1 月は年度の次の年');
            $this->assertSame('Ｊ・住宅 68 行目・右（ＮＯ 120）', $a['records'][5]['where']);
            $this->assertSame(6, $a['addCount']);

            // シートの件数（読まないシートも並べる）
            $this->assertSame([
                ['title' => 'Ｊ・住宅', 'kind' => PaperLedgerFile::REGISTER, 'code' => 'J', 'department' => '住宅事業部', 'rows' => 6, 'added' => 6, 'existing' => 0, 'errors' => 0, 'linked' => 0],
                ['title' => 'メモ', 'kind' => PaperLedgerFile::IGNORED, 'code' => null, 'department' => null, 'rows' => 0, 'added' => 0, 'existing' => 0, 'errors' => 0, 'linked' => 0],
            ], $a['sheets']);
        }
    }

    /** エラーの行は取り込まない・ほかの行は取り込む（D20）。どの行がなぜかを出す */
    public function test_rows_with_errors_are_left_out_and_the_rest_is_kept(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 90, 'fiscal_year' => 2025]);   // 前の年度の同じＮＯ（R7-J-090）は関わらない
        $draft = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-009', 'number_department_id' => $this->w['dept']->id]);

        $path = $this->paperLedger([
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '取り込む行'],
                [2, '8.02.30', '甲野', 'ない日付'],
                [3, '7.04.30', '甲野', '前の年度の書き残し'],
                [4, null, '甲野', '日付が無い'],
                [5, '8.05.10', '甲野', null],
                [6, '8.05.10', '甲野', str_repeat('長', 101)],
                [7, '8.05.10', str_repeat('名', 51), '申請者が長い'],
                [8, '8.05.10', null, '申請者は空でもよい'],
                [9, '8.05.11', '甲野', 'システムの番号と同じ'],
                [90, '8.06.01', '甲野', '前の年度と同じＮＯ'],
            ],
            'ＣＢ・賃貸' => [[1, '8.05.20', '乙山', '部門の無いシート'], [2, '8.05.21', '乙山', '部門の無いシート 2']],
            'ＨＢ・本部' => [],
        ], [
            // ＮＯ の誤り・空のＮＯ・同じＮＯ が 2 つ
            'Ｊ・住宅' => ['B15' => 'ＸＸ', 'C15' => '8.05.12', 'D15' => '甲野', 'E15' => 'ＮＯ が文字', 'B16' => null, 'C16' => '8.05.12', 'D16' => '甲野', 'E16' => 'ＮＯ が空',
                'B17' => 1, 'C17' => '8.05.13', 'D17' => '乙山', 'E17' => 'ＮＯ 1 がもう 1 つ'],
        ]);

        $a = PaperImportAnalysis::analyze($path, 2026);

        $this->assertSame(['R8-J-008', 'R8-J-090'], array_column($a['records'], 'number'));
        $this->assertNull($a['records'][0]['applicant_name']);
        $this->assertSame([
            ['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => '日付「8.02.30」が読めません（ない日付です）'],
            ['where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'message' => '日付「7.04.30」の年（7）が R8 年度と合いません'],
            ['where' => 'Ｊ・住宅 8 行目・左（ＮＯ 4）', 'message' => '日付が空です（件名などが書いてあるのに日付が無い行は取り込みません）'],
            ['where' => 'Ｊ・住宅 9 行目・左（ＮＯ 5）', 'message' => '件名が空です'],
            ['where' => 'Ｊ・住宅 10 行目・左（ＮＯ 6）', 'message' => '件名が 100 文字を超えています'],
            ['where' => 'Ｊ・住宅 11 行目・左（ＮＯ 7）', 'message' => '申請者が 50 文字を超えています'],
            ['where' => 'Ｊ・住宅 15 行目・左（ＮＯ ＸＸ）', 'message' => 'ＮＯ「ＸＸ」が 1〜99,999 の数ではありません'],
            ['where' => 'Ｊ・住宅 16 行目・左', 'message' => 'ＮＯ が空です'],
            ['where' => 'ＣＢ・賃貸', 'message' => '部門のアルファベット CB の部門が部門の管理にありません（2 件を取り込みません。部門を登録してから取り込み直してください）'],
            ['where' => 'Ｊ・住宅 5 行目・左（ＮＯ 1）', 'message' => '同じファイルの中に R8-J-001 がもう 1 つあります（Ｊ・住宅 17 行目・左（ＮＯ 1））'],
            ['where' => 'Ｊ・住宅 17 行目・左（ＮＯ 1）', 'message' => '同じファイルの中に R8-J-001 がもう 1 つあります（Ｊ・住宅 5 行目・左（ＮＯ 1））'],
            ['where' => 'Ｊ・住宅 13 行目・左（ＮＯ 9）', 'message' => 'R8-J-009 はシステムの申請がすでに使っています'],
        ], $a['rowErrors']);
        $this->assertSame(['added' => 2, 'errors' => 11], array_intersect_key($a['sheets'][0], ['added' => 0, 'errors' => 0]));
        $this->assertSame([null, 2, 2], [$a['sheets'][1]['department'], $a['sheets'][1]['rows'], $a['sheets'][1]['errors']], '部門の無いシートの行は数えて「取り込まない」に入れるが、エラーの文は 1 つにまとめる');
        $this->assertSame(0, $a['sheets'][2]['rows'], '部門の無い空のシートは何も出さない');
    }

    /** 金額の控えは、すべての受付簿の行に日付と件名（空白・全角半角を無視）で結び付く。1 つに決まらない行は選んでもらう */
    public function test_amounts_are_linked_by_the_date_and_the_subject(): void
    {
        $path = $this->paperLedger([
            'ＪＢ・住宅' => [[1, '8.05.13', '甲野', '平井⑤　見本邸少額工事契約'], [2, '8.05.22', '乙山', '道後　見本邸少額工事契約']],
            'Ｊ・住宅' => [[2, '8.05.18', '丙川', '見本町土地建物契約'], [3, '8.05.26', '丁田', '同じ日の同じ件名'], [4, '8.05.26', '丁田', '同じ日の同じ件名'], [5, '8.06.30', '甲野', 'どの控えにも当たらない']],
            'Ｍ・ミツワ不動産' => [[2, '8.05.18', '戊井', '見本ビル売買契約']],
            '住宅少額・金額' => ['amounts' => [
                [1, '8.05.13', '甲野', '平井⑤ 見本邸少額工事契約', 1200000, 850000],      // 空白のゆれ
                [2, '８．５．２２', '乙山', '道後見本邸少額工事契約', 980000.0, '700,000'],   // 全角・0 の付け方・小数の形の整数・文字の金額
            ]],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.18', '丙川', '見本町土地建物契約', 30000000, 27000000],          // Ｊ に当たる
                [2, '8.05.18', '戊井', '見本ビル売買契約', 50000000, 45500000],            // Ｍ に当たる（08 年度の控えと同じく Ｊ 以外にも）
                [3, '8.05.26', '丁田', '同じ日の同じ件名', 1000, 900],                     // 2 行に当たる → 選ぶ
                [4, '8.07.07', '己野', 'どこにも当たらない', 2000, 1000],                  // 当たらない → 選ぶ
                [5, '8.05.18', '庚原', '金額が文字', 'ABC', 100],                          // 金額の誤り → 入れない
                [6, '8.05.18', '庚原', '13 桁', 1000000000000, 0],                        // 12 桁を超える → 入れない
            ]],
            '門田契約 金額' => ['amounts' => []],                                           // 式だけの計算の跡
        ]);

        $a      = PaperImportAnalysis::analyze($path, 2026);
        $byKey  = collect($a['amounts'])->keyBy('where');
        $record = collect($a['records'])->keyBy('number');

        $this->assertSame([], $a['linkErrors']);
        $this->assertSame(['R8-JB-001', true], [$byKey['住宅少額・金額 5 行目（ＮＯ 1）']['linked'], $byKey['住宅少額・金額 5 行目（ＮＯ 1）']['auto']]);
        $this->assertSame([1200000, 850000, 350000], [$record['R8-JB-001']['amount'], $record['R8-JB-001']['cost'], $record['R8-JB-001']['profit']], '粗利益は式（=+F-G）を計算した値');
        $this->assertSame([980000, 700000, 280000], [$record['R8-JB-002']['amount'], $record['R8-JB-002']['cost'], $record['R8-JB-002']['profit']]);
        $this->assertSame([30000000, 50000000], [$record['R8-J-002']['amount'], $record['R8-M-002']['amount']]);
        $this->assertSame(4500000, $record['R8-M-002']['profit']);

        // 2 行に当たる行・当たらない行は結び付けない（選択肢は同じ日付の記録が先）
        $twin = $byKey['土地・建物契約・金額 7 行目（ＮＯ 3）'];
        $this->assertSame([null, false], [$twin['linked'], $twin['auto']]);
        $this->assertSame(['R8-J-003', 'R8-J-004'], array_slice(array_column($twin['candidates'], 'number'), 0, 2));
        $this->assertSame('R8-J-003　2026/05/26　丁田　同じ日の同じ件名（同じ日付）', $twin['candidates'][0]['label']);
        $this->assertCount(7, $twin['candidates'], '取り込める記録すべてを選べる');
        $this->assertSame([null, false], [$byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['linked'], $byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['auto']]);
        $this->assertNull($record['R8-J-003']['amount']);
        $this->assertNull($record['R8-J-005']['amount']);

        // 金額の誤りの行は出さずにエラー（その行の金額は入れない）
        $this->assertFalse($byKey->has('土地・建物契約・金額 9 行目（ＮＯ 5）'));
        $this->assertContains(['where' => '土地・建物契約・金額 9 行目（ＮＯ 5）', 'message' => '金額の控えの契約金額「ABC」が 12 桁までの数ではありません（この行の金額は入れません）'], $a['rowErrors']);
        $this->assertContains(['where' => '土地・建物契約・金額 10 行目（ＮＯ 6）', 'message' => '金額の控えの契約金額「1000000000000」が 12 桁までの数ではありません（この行の金額は入れません）'], $a['rowErrors']);
        $this->assertSame(0, collect($a['sheets'])->firstWhere('title', '門田契約 金額')['rows'], '式だけの行は読まない');
        $this->assertSame(['rows' => 6, 'linked' => 2], array_intersect_key(collect($a['sheets'])->firstWhere('title', '土地・建物契約・金額'), ['rows' => 0, 'linked' => 0]));

        // 選んだ結び付け（確定のフォームで送る形）。「結び付けない」も選べる
        $chosen = PaperImportAnalysis::analyze($path, 2026, [
            $twin['key'] => 'R8-J-004',
            $byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['key'] => 'R8-J-005',
            $byKey['住宅少額・金額 5 行目（ＮＯ 1）']['key'] => PaperImportAnalysis::NO_LINK,
        ]);
        $record = collect($chosen['records'])->keyBy('number');
        $this->assertSame([], $chosen['linkErrors']);
        $this->assertSame([null, 1000, 2000, null], [$record['R8-J-003']['amount'], $record['R8-J-004']['amount'], $record['R8-J-005']['amount'], $record['R8-JB-001']['amount']]);
        $this->assertFalse(collect($chosen['amounts'])->firstWhere('key', $twin['key'])['auto'], '選んだ行は自動ではない');
    }

    /**
     * 金額を書く前の控えの行（契約金額・工事原価・粗利益がどれも空）は結び付けない。工事原価が空の行は、式の粗利益（空のセルを 0 として
     * 計算した値）を入れない（点検 I-2。結び付けると「金額を補った」と数え、あとで本当の金額を補えなくなる）
     */
    public function test_amount_rows_without_amounts_are_not_linked(): void
    {
        $a = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約'], [2, '8.05.08', '乙山', '工事原価がまだの契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.07', '甲野', '金額をまだ書いていない契約', null, null],
                [2, '8.05.08', '乙山', '工事原価がまだの契約', 30000000, null],
            ]],
        ]), 2026);

        $empty = collect($a['amounts'])->firstWhere('where', '土地・建物契約・金額 5 行目（ＮＯ 1）');
        $this->assertSame([null, false, true, [], null], [$empty['linked'], $empty['auto'], $empty['empty'], $empty['candidates'], $empty['profit']], '粗利益の式の 0 を読まない');
        $records = collect($a['records'])->keyBy('number');
        $this->assertSame([null, null, null], [$records['R8-J-001']['amount'], $records['R8-J-001']['cost'], $records['R8-J-001']['profit']]);
        $this->assertSame([30000000, null, null], [$records['R8-J-002']['amount'], $records['R8-J-002']['cost'], $records['R8-J-002']['profit']], '工事原価が空なら粗利益（＝契約金額）を入れない');
        $this->assertSame(0, $a['fillCount']);

        // すでにある金額の空の記録にも、金額の無い行では「補う」としない
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'applicant_name' => '甲野', 'subject' => '金額をまだ書いていない契約']);
        $again = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約']],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約', null, null]]],
        ]), 2026);
        $this->assertSame([0, 0, null], [$again['addCount'], $again['fillCount'], $again['existing'][0]['fill']]);
    }

    /** 2 つの控えが同じ行に当たれば自動で結び付けない・選んだ先が同じ・取り込めない先は、確定できない誤りにする */
    public function test_two_amounts_for_one_record_are_not_linked(): void
    {
        $path = $this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '見本邸新築工事契約'], [2, '8.05.08', '乙山', '別の契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.07', '甲野', '見本邸新築工事契約', 1000, 500],
                [2, '8.5.7', '甲野', '見本邸 新築工事契約', 2000, 500],
            ]],
        ]);

        $a = PaperImportAnalysis::analyze($path, 2026);
        $this->assertSame([[null, false], [null, false]], array_map(fn (array $m) => [$m['linked'], $m['auto']], $a['amounts']));
        $this->assertNull($a['records'][0]['amount']);

        [$first, $second] = array_column($a['amounts'], 'key');
        $same = PaperImportAnalysis::analyze($path, 2026, [$first => 'R8-J-001', $second => 'R8-J-001']);
        $this->assertSame(['金額の控えの 土地・建物契約・金額 5 行目（ＮＯ 1）・土地・建物契約・金額 6 行目（ＮＯ 2） が同じ R8-J-001 に結び付いています。どちらかを「結び付けない」にしてください'], $same['linkErrors']);
        $this->assertNull($same['records'][0]['amount'], '同じ先に 2 つの金額を入れない');

        $gone = PaperImportAnalysis::analyze($path, 2026, [$first => 'R8-J-777', $second => PaperImportAnalysis::NO_LINK]);
        $this->assertSame(['土地・建物契約・金額 5 行目（ＮＯ 1） の結び付け先 R8-J-777 は、このファイルで取り込める記録にありません。選び直してください'], $gone['linkErrors']);
    }

    /** すでにある番号は中身を変えない（D6）。違えば注意・金額がすべて空の記録にだけ控えから補う */
    public function test_existing_numbers_are_kept_and_only_empty_amounts_are_filled(): void
    {
        $same    = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'applicant_name' => '甲野', 'subject' => '見本邸新築工事契約']);
        $changed = $this->paperRecord($this->w['dept'], ['seq' => 2, 'decided_on' => '2026-05-08', 'applicant_name' => '乙山', 'subject' => '手で直した件名']);
        $priced  = $this->paperRecord($this->w['dept'], ['seq' => 3, 'decided_on' => '2026-05-09', 'applicant_name' => '丙川', 'subject' => '金額のある契約', 'cost' => 5]);

        $a = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '見本邸新築工事契約'], [2, '8.05.08', '乙山', 'Excel の件名'], [3, '8.05.09', '丙川', '金額のある契約'], [4, '8.05.10', '丁田', '新しい契約']],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '見本邸新築工事契約', 1000, 400], [2, '8.05.09', '丙川', '金額のある契約', 3000, 1000]]],
        ]), 2026);

        $this->assertSame(['R8-J-004'], array_column($a['records'], 'number'));
        $this->assertSame([
            ['number' => 'R8-J-001', 'id' => $same->id, 'where' => 'Ｊ・住宅 5 行目・左（ＮＯ 1）', 'fill' => ['amount' => 1000, 'cost' => 400, 'profit' => 600]],
            ['number' => 'R8-J-002', 'id' => $changed->id, 'where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'fill' => null],
            ['number' => 'R8-J-003', 'id' => $priced->id, 'where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'fill' => null],
        ], $a['existing']);
        $this->assertSame([1, 1], [$a['addCount'], $a['fillCount']]);
        $this->assertSame([
            ['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => 'R8-J-002 はすでにあります。Excel と件名が違いますが、変えません（直すなら 1 件ずつ直してください）'],
            ['where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'message' => 'R8-J-003 は金額がすでにあるので、金額の控えの金額を入れません'],
        ], $a['warnings']);
        $this->assertSame(['added' => 1, 'existing' => 3], array_intersect_key($a['sheets'][0], ['added' => 0, 'existing' => 0]));
    }

    /** 次の番号（D7）: 今年度の部門は、足すＮＯ のいちばん大きいもの＋1 まで進む（今の番号より小さければそのまま）。過去の年度は関わらない */
    public function test_the_next_numbers_of_this_year(): void
    {
        ApprovalNumber::setNext($this->estate->fresh('company'), 40);
        $sheets = [
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', 'ア'], [34, '8.08.27', '乙山', 'イ']],
            'Ｍ・ミツワ不動産' => [[9, '8.07.01', '丙川', 'ウ']],
        ];

        $a = PaperImportAnalysis::analyze($this->paperLedger($sheets), 2026);
        $this->assertSame([
            ['department' => '住宅事業部', 'before' => 'R8-J-001', 'after' => 'R8-J-035'],
            ['department' => 'ミツワ不動産', 'before' => 'R8-M-040', 'after' => 'R8-M-040'],
        ], $a['nextNumbers']);

        $past = PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [[1, '7.05.07', '甲野', 'ア']]]), 2025);
        $this->assertSame(['R7-J-001'], array_column($past['records'], 'number'));
        $this->assertSame([], $past['nextNumbers'], '過去の年度の番号は採番に関わらない');
    }

    public function test_a_file_that_is_not_a_ledger_is_refused(): void
    {
        Log::spy();
        $text = tempnam(sys_get_temp_dir(), 'paper');
        file_put_contents($text, "ＮＯ,日付,申請者,件名\n1,8.05.07,甲野,見本\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($text));

        try {
            PaperImportAnalysis::analyze($text, 2026);
            $this->fail('CSV を読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。', $e->getMessage());
            Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === '紙の決裁の台帳を Excel として読めませんでした' && $context['exception'] instanceof \Throwable);
        }
    }

    /** 大きなファイルは読む前に断る（読み込んでから数えると、本番の 128M を使い切って捕まえられない 500 になる。点検 I-3） */
    public function test_a_huge_sheet_is_refused_before_it_is_read(): void
    {
        $path = $this->paperLedger(['メモ' => []], ['メモ' => ['A' . (PaperLedgerFile::MAX_SHEET_ROWS + 1) => '最後の行']], 'Xlsx');

        try {
            PaperImportAnalysis::analyze($path, 2026);
            $this->fail('大きなファイルを読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('台帳の行が多すぎます（すべてのシートで 6,000 行まで）。年度ごとの台帳のファイルか確かめてください。', $e->getMessage());
        }
    }

    /** 今日より先の決裁日は取り込まない（年度の和暦と暦の和暦の取り違え。点検 M-2） */
    public function test_a_date_after_today_is_an_error(): void
    {
        $a = PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [[1, '9.04.20', '甲野', '今日'], [2, '9.04.21', '乙山', '明日']]]), 2026);

        $this->assertSame(['R8-J-001'], array_column($a['records'], 'number'));
        $this->assertSame([['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => '日付「9.04.21」が今日より先の日付（2027/04/21）になります']], $a['rowErrors']);
    }

    /** 行が多すぎるファイルは読まない（年度ごとの台帳でないものを取り込まない） */
    public function test_too_many_rows_are_refused(): void
    {
        $rows = [];
        for ($no = 1; $no <= PaperLedgerFile::MAX_ROWS + 1; $no++) {
            $rows[] = [$no, '8.05.07', '甲野', "契約{$no}"];
        }

        try {
            PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => $rows], [], 'Xlsx'), 2026);
            $this->fail('3,001 行を読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('1 回に読めるのは 3,000 行までです。年度ごとのファイルか確かめてください。', $e->getMessage());
        }
    }
}
