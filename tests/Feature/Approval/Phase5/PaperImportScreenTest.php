<?php

namespace Tests\Feature\Approval\Phase5;

use App\Http\Controllers\Approval\PaperImportController;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 紙の決裁台帳の取り込みの画面（画面⑪。段階5 設計書 §5.9・§5.10・D6・D7・D20）。アップロード → 確認 → 確定の往復。
 *
 * ⚠ 確定は、確認の画面が描いたフォームをブラウザと同じように分解して送り返す（送り先も中身も自前で組まない。Bug #47）。
 *   `links[シートの番号:行]` の名前は parse_str でブラウザと同じ形の配列にする（SubmitsScreenForms と同じ）
 * ⚠ 台帳は作りもの（BuildsPaperLedgers。本物の台帳はリポジトリに入れない＝D24）
 */
class PaperImportScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;
    use ChecksDoubleSubmit;
    use ComparesJsonColumns;
    use ParsesForms;

    private const FILE_NAME = '08年度・決裁台帳.xls';

    private array $w;

    private ApprovalDepartment $estate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w      = $this->approvalWorld();                                 // 住宅事業部 J
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);
        $this->admin  = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 受付簿 3 行と、2 行目に結び付く金額の控え 1 行 */
    private function ledger(array $sheets = []): string
    {
        return $this->paperLedger($sheets + [
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.05.18', '乙山', '見本邸新築工事契約'],
                [3, '8.06.01', '丙川', '見本ビル改修'],
            ],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.18', '乙山', '見本邸新築工事契約', 30000000, 24000000],
            ]],
        ]);
    }

    private function preview(string $path, int $year = 2026, string $name = self::FILE_NAME): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import'))
            ->post(route('approvals.admin.papers.import.preview'), [
                'ledger_file' => new UploadedFile($path, $name, null, null, true),
                'year'        => $year,
            ]);
    }

    /** 確認の画面が描いた確定のフォーム */
    private function confirmForm(TestResponse $preview): array
    {
        $preview->assertOk();
        $form = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.papers.import.execute') . '"');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        return $form;
    }

    /**
     * 確定のフォームを送る。⚠ リファラーは確認の画面（preview の POST の応答）＝ POST 専用の URL（Bug #64）
     *
     * @param array<string, string> $choices 選び直す select（name => value）
     */
    private function send(array $form, array $choices = []): TestResponse
    {
        foreach ($choices as $name => $value) {
            $this->assertArrayHasKey($name, $form['fields'], "確定のフォームに {$name} が無い");
        }
        parse_str(http_build_query(array_merge($form['fields'], $choices)), $fields);

        return $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import.preview'))
            ->post($form['action'], $fields);
    }

    // --- 入口 ---

    public function test_the_import_screen_offers_the_years_and_the_list_links_to_it(): void
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="2026" selected>R8 年度（2026）</option>', $html, '今の年度を先に選ぶ');
        $this->assertStringContainsString('<option value="1989" >H1 年度（1989）</option>', $html, '平成元年度まで選べる');
        $this->assertStringNotContainsString('value="2027"', $html, '先の年度は選べない');
        $this->assertSame(1, substr_count($html, 'action="' . route('approvals.admin.papers.import.preview') . '"'));

        // 一覧からの入口（入口と受け側を対で。Bug #47）
        $list = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('approvals.admin.papers.import') . '"', $list);
    }

    /**
     * ファイルの名前の「08年度」から年度を選ぶ（画面の script を node で動かす。振る舞いの正本は実駆動。Bug #47）
     */
    public function test_the_year_is_picked_from_the_file_name(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので年度の先選びの JavaScript の実駆動を飛ばす');
        }
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->getContent();
        $this->assertSame(1, preg_match('/<script>\s*(function paperLedgerYear\(.*?)<\/script>/su', $html, $m), '年度を選ぶ script が描かれていない');
        $this->assertStringContainsString('onchange="paperLedgerFileChosen(this)"', $html, 'ファイルの選択が script を呼んでいない');

        $choices = json_encode(array_map('strval', array_keys(ApprovalPaperRecord::yearChoices())));
        $cases   = [
            '08年度・決裁申請書.xls' => '2026',     // 令和
            '０８年度　台帳.xlsx'      => '2026',     // 全角の数字
            '7年度.xls'                => '2025',
            '30年度.xls'               => '2018',     // 令和 30 年度は先なので平成 30 年度
            '台帳.xls'                 => null,       // 年度が無い
            '99年度.xls'               => null,       // どちらも選択肢に無い
        ];
        $harness = $m[1] . "\nprocess.stdout.write(JSON.stringify(" . json_encode(array_keys($cases)) . ".map(function (n) { return paperLedgerYear(n, {$choices}); })));";
        $output  = shell_exec(sprintf('%s -e %s 2>&1', escapeshellarg($node), escapeshellarg($harness)));

        $this->assertSame(array_values($cases), json_decode((string) $output, true), "node の出力: {$output}");
    }

    // --- 確認 ---

    public function test_an_upload_shows_the_confirmation_without_writing(): void
    {
        $html = $this->preview($this->ledger())->assertOk()->getContent();

        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()], '確認では何も書かない');
        $this->assertStringContainsString('足す 3 件', $html);
        $this->assertStringContainsString('R8-J-003', $html);
        $this->assertStringContainsString('30,000,000円', $html, '金額の控えの金額を足す記録に付ける');
        $this->assertStringContainsString('<span class="font-mono">R8-J-002</span>（自動）', $html);
        $this->assertStringContainsString('住宅事業部: <span class="font-mono">R8-J-001</span> → <span class="font-mono font-semibold">R8-J-004</span>', $html, '次の番号が進むことを出す');
        $this->assertSubmitOnceForm($html, route('approvals.admin.papers.import.execute'));

        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->assertSame([self::FILE_NAME, '2026'], [$form['fields']['file_name'], $form['fields']['year']]);
    }

    /** 取り込まない行（赤）と注意（黄）は別のブロックに別の言い方で（Bug #54 ④）。取り込まない行があっても、ほかは取り込める */
    public function test_rows_with_errors_and_warnings_are_shown_apart(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 3, 'subject' => '手で直した件名', 'decided_on' => '2026-06-01', 'applicant_name' => '丙川']);

        $html = $this->preview($this->ledger([
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.02.30', '乙山', 'ない日付'],
                [3, '8.06.01', '丙川', '見本ビル改修'],
            ],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '見本町　用地購入', null, null]]],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('取り込まない Ｊ・住宅 6 行目・左（ＮＯ 2）: 日付「8.02.30」が読めません（ない日付です）', $html);
        $this->assertStringContainsString('⚠ Ｊ・住宅 7 行目・左（ＮＯ 3）: R8-J-003 はすでにあります。Excel と件名が違いますが、変えません', $html);
        $this->assertStringContainsString('<span class="font-mono">R8-J-003</span>（Ｊ・住宅 7 行目・左（ＮＯ 3））:', $html);
        $this->assertStringContainsString('金額がありません（結び付けません）', $html, '金額を書く前の控えの行は結び付けない');
        $this->assertSubmitOnceForm($html, route('approvals.admin.papers.import.execute'));
    }

    // --- 確定 ---

    public function test_confirming_imports_the_records_and_advances_the_next_number(): void
    {
        $response = $this->send($this->confirmForm($this->preview($this->ledger())));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertOk()
            ->assertSee('3 件を取り込みました（金額を補った記録 0 件）。次の番号: 住宅事業部 R8-J-004。');

        $import = ApprovalPaperImport::sole();
        $this->assertSame([self::FILE_NAME, 2026, 3, 0, $this->admin->id], [$import->file_name, $import->fiscal_year, $import->added_count, $import->filled_count, $import->imported_by]);

        $records = ApprovalPaperRecord::orderBy('seq')->get();
        $this->assertSame(['R8-J-001', 'R8-J-002', 'R8-J-003'], $records->pluck('number')->all());
        $this->assertSame([$import->id], $records->pluck('import_id')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $records->pluck('created_by')->unique()->values()->all());
        $this->assertSame([30000000, 24000000, 6000000], [$records[1]->amount, $records[1]->cost, $records[1]->profit], '粗利益は控えの式の値');
        $this->assertSame('2026-05-18', $records[1]->decided_on->format('Y-m-d'));

        $this->assertSame(4, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next'], '今年度の次の番号が紙の番号の次まで進む（D7）');

        $log = ApprovalSettingLog::where('action', 'paper.imported')->sole();
        $this->assertSame([$this->admin->id, 'approval_paper_import', $import->id], [$log->actor_user_id, $log->target_type, $log->target_id]);
        // ⚠ MySQL は JSON のキーを並べ替えて返す（キーの並びに頼らずに比べる）
        $this->assertSameIgnoringKeyOrder(['file_name' => self::FILE_NAME, 'fiscal_year' => 2026, 'added' => 3, 'filled' => 0, 'next_numbers' => ['J' => 4]], $log->new_values);
    }

    /** 過去の年度の台帳は、次の番号に関わらない（§5.11） */
    public function test_a_past_year_does_not_touch_the_next_number(): void
    {
        $this->send($this->confirmForm($this->preview($this->paperLedger(['Ｊ・住宅' => [[5, '7.05.07', '甲野', '前の年度の決裁']]]), 2025, '07年度.xls')));

        $this->assertSame('R7-J-005', ApprovalPaperRecord::sole()->number);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);
        $this->assertSame([], ApprovalSettingLog::where('action', 'paper.imported')->sole()->new_values['next_numbers']);
    }

    /** すでにある記録は中身を変えず、金額がすべて空なら控えの金額だけを補う（D6） */
    public function test_an_existing_record_only_gets_its_empty_amounts_filled(): void
    {
        $kept = $this->paperRecord($this->w['dept'], ['seq' => 2, 'subject' => '見本邸新築工事契約', 'decided_on' => '2026-05-18', 'applicant_name' => '乙山']);

        $html = $this->preview($this->ledger())->getContent();
        $this->assertStringContainsString('金額を補います（契約金額 30,000,000円・工事原価 24,000,000円・粗利益 6,000,000円）', $html);
        $this->send($this->confirmForm($this->preview($this->ledger())));

        $kept->refresh();
        $this->assertSame([30000000, 24000000, 6000000, null], [$kept->amount, $kept->cost, $kept->profit, $kept->import_id], '金額だけを補い、取り込み 1 回分には入れない（取り消しで消さない）');
        $this->assertSame([2, 1], [ApprovalPaperImport::sole()->added_count, ApprovalPaperImport::sole()->filled_count]);
    }

    /**
     * 確定でファイルを読み直す（画面が送ってきた hidden を信用しない）。確認のあとでほかの人が同じ番号を登録していたら、それを足さない
     */
    public function test_the_confirmation_reads_the_file_again(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'subject' => 'ほかの人が先に登録']);

        $this->send($form);

        $this->assertSame('ほかの人が先に登録', ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->subject);
        $this->assertSame(2, ApprovalPaperImport::sole()->added_count);
    }

    /** 確認のあとでほかの人が全部取り込んでいたら、何もせずに断る */
    public function test_nothing_left_to_import_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->send($this->confirmForm($this->preview($this->ledger())));   // ほかの画面から先に取り込んだ

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form));

        $this->assertRefused($response, route('approvals.admin.papers.import'), PaperImportController::NOTHING_TO_IMPORT, $this->admin);
        $this->assertSame(0, $writes);
        $this->assertSame(1, ApprovalPaperImport::count());
    }

    /** 二度押し・再送信は 1 回だけ効く（1 回限りの鍵。ファイルを読み直す前に断る） */
    public function test_a_second_submit_is_refused_without_writing(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->send($form);

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form));

        $this->assertRefused($response, route('approvals.admin.papers.import'), PaperImportController::ALREADY_SENT, $this->admin);
        $this->assertSame(0, $writes);
        $this->assertSame([1, 3], [ApprovalPaperImport::count(), ApprovalPaperRecord::count()]);
    }

    // --- 金額の控えの結び付けを選ぶ ---

    /** 受付簿の 2 つの行（J と M）に当たる控えの行 */
    private function ambiguousLedger(): string
    {
        return $this->ledger([
            'Ｍ・ミツワ不動産' => [[1, '8.06.10', '丁田', '見本倉庫購入']],
            'Ｊ・住宅'        => [[1, '8.06.10', '丁田', '見本倉庫購入'], [2, '8.05.18', '乙山', '見本邸新築工事契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.06.10', '丁田', '見本倉庫購入', 50000000, 42000000],
                [2, '8.05.18', '乙山', '見本邸新築工事契約', 30000000, 24000000],
            ]],
        ]);
    }

    public function test_an_ambiguous_amount_row_is_chosen_on_the_confirmation(): void
    {
        $preview = $this->preview($this->ambiguousLedger());
        $html    = $preview->getContent();
        $this->assertStringContainsString('結び付け先が 1 つに決まらない行が 1 行あります。', $html);
        $form = $this->confirmForm($preview);
        $key  = $this->linkField($form);
        $this->assertSame('none', $form['fields'][$key], '選ぶまでは結び付けない');

        // ⚠ 先頭（結び付けない）以外の値で往復させる（ParsesForms の select の注意）
        $this->send($form, [$key => 'R8-M-001']);

        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);
        $this->assertNull(ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->amount);
        $this->assertSame(30000000, ApprovalPaperRecord::where('number', 'R8-J-002')->sole()->amount, '自動の結び付けはそのまま');
    }

    /** 自動で結び付いた記録をもう一度選ぶと、確認の画面をもう一度出して選び直してもらう（何も書かない） */
    public function test_choosing_a_record_twice_shows_the_confirmation_again(): void
    {
        $form = $this->confirmForm($this->preview($this->ambiguousLedger()));
        $key  = $this->linkField($form);

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form, [$key => 'R8-J-002']));

        $response->assertOk();
        $this->assertSame(0, $writes);
        $html = $response->getContent();
        $this->assertStringContainsString('金額の控えの結び付けを選び直してください。', $html);
        $this->assertStringContainsString('金額の控えの 土地・建物契約・金額 5 行目（ＮＯ 1）・土地・建物契約・金額 6 行目（ＮＯ 2） が同じ R8-J-002 に結び付いています。', $html);

        // 選び直した画面は新しい鍵で、選んだ値を残している。選び直して送れば取り込める
        $again = $this->confirmForm($response);
        $this->assertNotSame($form['fields']['import_token'], $again['fields']['import_token']);
        $this->assertSame('R8-J-002', $again['fields'][$key]);
        $this->send($again, [$key => 'R8-J-001']);
        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->amount);
    }

    /**
     * 結び付けなかった控えは、同じ台帳を取り込み直したときに結び付けられる（足す記録が 0 件でも、選べる行があれば確定のフォームを描く）
     */
    public function test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload(): void
    {
        $this->send($this->confirmForm($this->preview($this->ambiguousLedger())));   // 1 回目は結び付けない
        $this->assertNull(ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);

        $preview = $this->preview($this->ambiguousLedger());
        $this->assertStringContainsString('足す 0 件', $preview->getContent());
        $form = $this->confirmForm($preview);
        $this->send($form, [$this->linkField($form) => 'R8-M-001']);

        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);
        $second = ApprovalPaperImport::latest('id')->first();
        $this->assertSame([0, 1], [$second->added_count, $second->filled_count]);
        $this->assertSame(3, ApprovalPaperRecord::count(), '足すものは無い');
    }

    /** 結び付けを選ぶ select の名前（選べる行は 1 つだけの台帳で使う） */
    private function linkField(array $form): string
    {
        $keys = array_values(array_filter(array_keys($form['fields']), fn (string $name) => str_starts_with($name, 'links[')));
        $this->assertCount(1, $keys, '選べる結び付けが 1 つでない');

        return $keys[0];
    }

    // --- 断りの戻り先（確認の画面は POST の応答。Bug #64） ---

    public function test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen(): void
    {
        $bad = UploadedFile::fake()->createWithContent('台帳.csv', "a,b\n");
        foreach ([
            [[], '台帳のファイルを選んでください。'],
            [['ledger_file' => $bad, 'year' => 2026], '台帳のファイル（.xls・.xlsx）を選んでください。'],
            [['ledger_file' => new UploadedFile($this->ledger(), self::FILE_NAME, null, null, true), 'year' => 2027], '年度を選び直してください。'],
        ] as [$fields, $message]) {
            $response = $this->actingAs($this->admin)
                ->from(route('approvals.admin.papers.import.preview'))
                ->post(route('approvals.admin.papers.import.preview'), $fields);

            $this->assertSame(302, $response->getStatusCode(), $message);
            $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'), '取り込みの画面へ戻っていない（preview へ戻ると GET で 405）');
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk()->assertSee($message);
        }
    }

    public function test_a_file_that_is_not_excel_is_refused_on_the_import_screen(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper') . '.xls';
        file_put_contents($path, 'Excel ではない中身');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $response = $this->preview($path);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertSee('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。');
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    // --- 確定のあいだの競合（ロックのあとで確かめる。コントローラが断る経路を HTTP で通す。点検 I-4） ---

    /** 確認の画面のあとで社長の判断が同じ番号を付けたら、何も書かずに取り込みの画面へ戻す（次の番号も戻る） */
    public function test_a_system_number_taken_during_the_confirmation_is_refused(): void
    {
        $draft = $this->draftFor($this->w);
        $form  = $this->confirmForm($this->preview($this->ledger()));
        $done  = false;
        // 連番の行をロックして進めた瞬間に、ほかの人の判断が R8-J-001 を付けた（同じトランザクションの中なので、断られたら一緒に戻る）
        ApprovalNumberSequence::updated(function () use (&$done, $draft): void {
            if (! $done) {
                $done = true;
                DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $this->w['dept']->id]);
            }
        });

        $response = $this->send($form);

        $this->assertRefused($response, route('approvals.admin.papers.import'), '確認の画面を出したあとで、システムの申請が R8-J-001 を使いました。もう一度アップロードして確かめてください。', $this->admin);
        $this->assertSame([0, 0, null], [ApprovalPaperRecord::count(), ApprovalPaperImport::count(), $draft->fresh()->number]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);
    }

    /**
     * 確認の画面を見ているあいだに社長の判断が同じ番号を付けたら、確定で読み直すとその行は「取り込まない行」に回って黙って落ちる。
     * それを残りだけ取り込んで済ませず、何も書かずに断る（最後の点検 I-1。確認の画面が持たせた「足すとした決裁No」で消えた番号を知る）
     */
    public function test_a_system_number_taken_while_the_confirmation_is_open_is_refused(): void
    {
        $draft = $this->draftFor($this->w);
        $form  = $this->confirmForm($this->preview($this->ledger()));   // 確認の画面: 足す 3 件（R8-J-001〜003）
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-002', 'number_department_id' => $this->w['dept']->id]);   // 見ているあいだに社長の判断

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form));

        $this->assertRefused($response, route('approvals.admin.papers.import'), '確認の画面を出したあとで、システムの申請が R8-J-002 を使いました。もう一度アップロードして確かめてください。', $this->admin);
        $this->assertSame(0, $writes);
        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);
    }

    /** 確認の画面のあとでほかの管理者が同じ番号の紙の決裁を登録していたら（一意の索引）、何も書かずに取り込みの画面へ戻す */
    public function test_a_paper_number_taken_during_the_confirmation_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $done = false;
        ApprovalPaperImport::created(function () use (&$done): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->insert([
                    'department_id' => $this->w['dept']->id, 'fiscal_year' => 2026, 'seq' => 2, 'number' => 'R8-J-002', 'decided_on' => '2026-05-18',
                    'subject' => 'ほかの管理者が登録', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->send($form);

        $this->assertRefused($response, route('approvals.admin.papers.import'), 'ほかの人が同じ番号の紙の決裁を登録しました。もう一度アップロードして確かめてください。', $this->admin);
        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
    }

    /** 確認の画面のあとで手で金額を入れた記録には、控えの金額を入れない（確定の瞬間にも空かを確かめる。D6） */
    public function test_amounts_typed_during_the_confirmation_are_not_overwritten(): void
    {
        $kept = $this->paperRecord($this->w['dept'], ['seq' => 2, 'subject' => '見本邸新築工事契約', 'decided_on' => '2026-05-18', 'applicant_name' => '乙山']);
        $form = $this->confirmForm($this->preview($this->ledger()));
        $done = false;
        ApprovalPaperImport::created(function () use (&$done, $kept): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->where('id', $kept->id)->update(['amount' => 123]);
            }
        });

        $this->send($form);

        $this->assertSame([123, null, null], [$kept->fresh()->amount, $kept->fresh()->cost, $kept->fresh()->profit]);
        $this->assertSame([2, 0], [ApprovalPaperImport::sole()->added_count, ApprovalPaperImport::sole()->filled_count]);
    }

    /** 壊れた送信（hidden のファイルが base64 でない）は取り込みの画面へ戻す */
    public function test_a_broken_file_in_the_confirmation_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $form['fields']['ledger_data'] = '***';

        $this->assertRefused($this->send($form), route('approvals.admin.papers.import'), '取り込むファイルを読み取れませんでした。もう一度アップロードしてください。', $this->admin);
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    /** 知らせる次の番号は、確定で実際に進めた値（確認の画面のあとで開始番号が変わっても合う。点検 M-6） */
    public function test_the_next_number_in_the_notice_is_the_one_actually_set(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        ApprovalNumber::setNext($this->w['dept']->fresh('company'), 50);

        $response = $this->send($form);

        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertSee('3 件を取り込みました（金額を補った記録 0 件）。次の番号: 住宅事業部 R8-J-050。');
    }

    public function test_a_broken_confirmation_goes_back_to_the_import_screen(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import.preview'))
            ->post(route('approvals.admin.papers.import.execute'), []);   // ledger_data が無い（壊れた・書き換えた送信）

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk();
    }
}
