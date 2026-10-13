<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 紙の決裁の 1 件ずつの登録・直し・削除と、取り込み 1 回分の取り消し（画面⑪。段階5 設計書 §5.10・§5.11・D7・D9）。
 *
 * ⚠ 画面が描いたフォームを分解して送り返す（送り先・HTTP メソッド・選択肢を自前で組まない。Bug #47）。
 *   select は先頭以外の値で往復させる（ParsesForms の注意）
 */
class PaperRecordCrudTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;
    use ParsesForms;

    private array $w;

    private ApprovalDepartment $estate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w      = $this->approvalWorld();
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M', 'sort_order' => 9]);
        $this->admin  = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function storeForm(): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.store') . '"');
    }

    private function editForm(ApprovalPaperRecord $record): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.update', $record) . '"');
    }

    /** フォームを入れた値で送る（ブラウザと同じく、フォームの画面から） */
    private function send(array $form, array $fields, string $from): TestResponse
    {
        foreach (array_keys($fields) as $name) {
            $this->assertArrayHasKey($name, $form['fields'], "フォームに {$name} が無い");
        }

        return $this->actingAs($this->admin)->from($from)->post($form['action'], array_merge($form['fields'], $fields));
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'fiscal_year'    => '2026',
            'department_id'  => (string) $this->estate->id,
            'seq'            => '21',
            'decided_on'     => '2026-06-10',
            'applicant_name' => '丁田',
            'subject'        => '見本倉庫購入',
            'amount'         => '５０，０００，０００円',
            'cost'           => '42,000,000',
            'profit'         => '8000000',
            'note'           => "原本は総務の棚\r\n2 段目",
        ], $overrides);
    }

    // --- 登録 ---

    public function test_a_paper_record_is_registered_from_the_form(): void
    {
        $response = $this->send($this->storeForm(), $this->fields(), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertSee('R8-M-021 を登録しました。');

        $record = ApprovalPaperRecord::sole();
        $this->assertSame(
            ['R8-M-021', $this->estate->id, 2026, 21, '2026-06-10', '丁田', '見本倉庫購入', 50000000, 42000000, 8000000, "原本は総務の棚\n2 段目", null, $this->admin->id, null],
            [$record->number, $record->department_id, $record->fiscal_year, $record->seq, $record->decided_on->format('Y-m-d'), $record->applicant_name, $record->subject,
                $record->amount, $record->cost, $record->profit, $record->note, $record->import_id, $record->created_by, $record->updated_by]
        );
        $this->assertSame(22, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '今年度の番号は次の番号を進める（D7）');

        $log = ApprovalSettingLog::where('action', 'paper.created')->sole();
        $this->assertSame([$this->admin->id, 'approval_paper_record', $record->id], [$log->actor_user_id, $log->target_type, $log->target_id]);
        $this->assertSame(['R8-M-021', '2026', $this->estate->id, 21, '2026-06-10'], [$log->new_values['number'], (string) $log->new_values['fiscal_year'], $log->new_values['department_id'], $log->new_values['seq'], $log->new_values['decided_on']]);
    }

    public function test_the_optional_fields_can_be_left_empty_and_a_past_year_keeps_the_numbering(): void
    {
        $this->send($this->storeForm(), $this->fields(['fiscal_year' => '2018', 'decided_on' => '2018-06-10', 'applicant_name' => '　', 'amount' => '', 'cost' => '', 'profit' => '-300,000', 'note' => '']), route('approvals.admin.papers.create'));

        $record = ApprovalPaperRecord::sole();
        $this->assertSame(['H30-M-021', null, null, null, -300000, null], [$record->number, $record->applicant_name, $record->amount, $record->cost, $record->profit, $record->note]);
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '過去の年度は次の番号に関わらない');
    }

    /**
     * 決裁日は選んだ年度の期間の中で、今日より前の日だけ（今日より先・まだ始まっていない会社の年度の番号を入れると、次の番号を進められずに、
     * あとでシステムの採番と重なる。点検 M-2）
     */
    public function test_a_decided_date_outside_the_year_or_after_today_is_refused(): void
    {
        foreach (['2026-04-30' => '決裁日 2026/04/30 は R8 年度の期間（2026/05/01〜2027/04/30）の外です。年度か決裁日を直してください。', '2026-10-09' => '決裁日に今日より先の日付は入れられません。'] as $day => $message) {
            $response = $this->send($this->storeForm(), $this->fields(['decided_on' => $day]), route('approvals.admin.papers.create'));

            $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'), $day);
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertSee($message);
        }
        $this->assertSame(0, ApprovalPaperRecord::count());
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    public function test_bad_values_are_refused_with_the_input_kept(): void
    {
        $response = $this->send($this->storeForm(), $this->fields(['seq' => '0', 'amount' => '１２ａ', 'decided_on' => '2026-02-30', 'subject' => '']), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        foreach (['ＮＯ は 1 から 99,999 までで入力してください。', '金額「12a」は円の数で入力してください（マイナスも入れられます）。', '決裁日は正しい日付で入力してください。', '件名を入力してください。'] as $message) {
            $this->assertStringContainsString(e($message), $html);
        }
        $this->assertStringContainsString('value="丁田"', $html, '入れた中身を残す');
        $this->assertStringContainsString('<option value="' . $this->estate->id . '" selected>', $html, '選んだ部門を残す');
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    /** システムの申請の番号・ほかの紙の決裁の番号とは重ねられない。断ったら次の番号も進めない */
    public function test_a_number_already_in_use_is_refused(): void
    {
        $draft = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-M-021', 'number_department_id' => $this->estate->id]);
        $this->paperRecord($this->estate, ['seq' => 22]);
        ApprovalNumber::setNext($this->estate->fresh('company'), 23);

        foreach (['21' => 'R8-M-021 はシステムの申請がすでに使っています。ＮＯ を確かめてください。', '22' => 'R8-M-022 の紙の決裁はすでにあります。ＮＯ を確かめてください。'] as $seq => $message) {
            $response = $this->send($this->storeForm(), $this->fields(['seq' => (string) $seq]), route('approvals.admin.papers.create'));
            $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertSee($message);
        }
        $this->assertSame(1, ApprovalPaperRecord::count());

        // 次の番号より大きい番号で断られたときも、次の番号は戻る（ロックと同じトランザクション）
        $this->paperRecord($this->estate, ['seq' => 40]);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-M-030']);
        $this->send($this->storeForm(), $this->fields(['seq' => '30']), route('approvals.admin.papers.create'));
        $this->assertSame(23, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    // --- 直し ---

    public function test_a_record_is_edited_and_only_the_changes_are_logged(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 5, 'decided_on' => '2026-05-20', 'amount' => 1000]);
        $form   = $this->editForm($record);
        $this->assertSame([(string) $this->estate->id, '5', '2026-05-20', '1000'], [$form['fields']['department_id'], $form['fields']['seq'], $form['fields']['decided_on'], $form['fields']['amount']], '今の値で埋まる');
        $this->assertSame('PUT', $form['method']);

        $response = $this->send($form, ['subject' => '直した件名', 'amount' => '2,000'], route('approvals.admin.papers.edit', $record));

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $record->refresh();
        $this->assertSame(['直した件名', 2000, $this->admin->id], [$record->subject, $record->amount, $record->updated_by]);
        $log = ApprovalSettingLog::where('action', 'paper.updated')->sole();
        // ⚠ MySQL は JSON のキーを並べ替えて返す（キーの並びに頼らずに比べる）
        $this->assertSameIgnoringKeyOrder(['subject' => '見本邸新築工事契約', 'amount' => 1000], $log->old_values);
        $this->assertSameIgnoringKeyOrder(['subject' => '直した件名', 'amount' => 2000], $log->new_values);
    }

    /** 番号を直すと組み立て直し、今年度なら次の番号を進める。重なる番号には直せない */
    public function test_the_number_can_be_changed_but_not_onto_another_record(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 5]);
        $this->paperRecord($this->estate, ['seq' => 6]);

        $this->send($this->editForm($record), ['seq' => '6'], route('approvals.admin.papers.edit', $record));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->assertSee('R8-M-006 の紙の決裁はすでにあります。ＮＯ を確かめてください。');
        $this->assertSame('R8-M-005', $record->fresh()->number);

        $this->send($this->editForm($record), ['seq' => '35', 'department_id' => (string) $this->w['dept']->id], route('approvals.admin.papers.edit', $record));
        $this->assertSame(['R8-J-035', $this->w['dept']->id], [$record->fresh()->number, $record->fresh()->department_id]);
        $this->assertSame(36, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);

        // 同じ番号のまま保存できる（自分とは重ならない）
        $this->send($this->editForm($record->fresh()), ['subject' => '同じ番号のまま'], route('approvals.admin.papers.edit', $record));
        $this->assertSame('同じ番号のまま', $record->fresh()->subject);
    }

    // --- 削除 ---

    public function test_a_record_is_deleted_without_moving_the_number_back(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 21]);
        ApprovalNumber::advancePast($this->estate->fresh('company'), 2026, 21);
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->getContent();
        $this->assertStringContainsString("return confirm('R8-M-021 を削除します。次に付く番号は戻りません。よろしいですか。');", $html);
        // ⚠ 直しと削除は同じ URL（PUT と DELETE）。直しのフォームの後ろから探す
        $needle = 'action="' . route('approvals.admin.papers.destroy', $record) . '"';
        $form   = $this->parseForm(substr($html, strpos($html, $needle) + 1), $needle);
        $this->assertSame('DELETE', $form['method']);

        $response = $this->actingAs($this->admin)->post($form['action'], $form['fields']);

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->assertSame(0, ApprovalPaperRecord::count());
        $this->assertSame(22, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '次の番号は戻さない（D9）');
        $this->assertSame('R8-M-021', ApprovalSettingLog::where('action', 'paper.deleted')->sole()->old_values['number']);
    }

    // --- 取り込み 1 回分の取り消し ---

    /** 取り込み 1 回分（足した 3 件。そのうち 1 件はあとで手で直した）と、その回に金額を補っただけの記録 */
    private function importedBatch(): array
    {
        $import = ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 3, 'filled_count' => 1, 'imported_by' => $this->admin->id]);
        foreach ([1, 2, 3] as $seq) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq, 'import_id' => $import->id]);
        }
        ApprovalPaperRecord::where('seq', 2)->update(['updated_by' => $this->admin->id, 'subject' => '手で直した']);
        $filled = $this->paperRecord($this->w['dept'], ['seq' => 9, 'amount' => 500]);   // 前からあった記録（金額だけ補った）
        ApprovalNumber::advancePast($this->w['dept']->fresh('company'), 2026, 3);

        return [$import, $filled];
    }

    /** 一覧を開いて、描いた取り消しのフォームを分解する */
    private function undoForm(ApprovalPaperImport $import): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.imports.undo', $import) . '"');
    }

    public function test_an_import_is_undone_with_the_records_it_added(): void
    {
        [$import, $filled] = $this->importedBatch();
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->getContent();
        $this->assertStringContainsString('この取り込みで足した 3 件を消します。そのあと手で直した 1 件も消えます。金額を補った 1 件は、記録も金額も残ります。次に付く番号は戻りません。よろしいですか。', $html);
        $this->assertStringContainsString('取り消す（今 3 件・うち手で直した 1 件）', $html);

        $form     = $this->undoForm($import);
        $response = $this->actingAs($this->admin)->post($form['action'], $form['fields']);

        $this->assertSame(route('approvals.admin.papers.index'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertSee('取り込みを取り消しました（3 件を消しました）。次の番号は戻していません。');
        $this->assertSame([$filled->id], ApprovalPaperRecord::pluck('id')->all(), '金額を補っただけの記録は消さない');
        $import->refresh();
        $this->assertSame([3, $this->admin->id], [$import->undone_count, $import->undone_by]);
        $this->assertNotNull($import->undone_at);
        $this->assertSame(4, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next'], '次の番号は戻さない（D9）');
        $this->assertSameIgnoringKeyOrder(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'deleted' => 3, 'edited' => 1], ApprovalSettingLog::where('action', 'paper.import_undone')->sole()->new_values);

        // 取り消した回にはボタンを出さない
        $this->assertStringNotContainsString('action="' . route('approvals.admin.papers.imports.undo', $import) . '"', $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->getContent());
    }

    /** 一覧で見せた件数・手で直した件数と今が違えば消さない・取り消した回はもう一度取り消せない（どれも描いたフォームから送る） */
    public function test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done(): void
    {
        [$import] = $this->importedBatch();
        $form = $this->undoForm($import);
        ApprovalPaperRecord::where('seq', 3)->update(['updated_by' => $this->admin->id]);   // 見せたあとでほかの管理者が 1 件直した

        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))
            ->assertSee('この取り込みで足した記録が変わりました（一覧では 3 件・うち手で直した 1 件、今は 3 件・うち 2 件）。一覧で確かめてから、もう一度取り消してください。');
        $this->assertSame(4, ApprovalPaperRecord::count());
        $this->assertNull($import->fresh()->undone_at);

        ApprovalPaperRecord::where('seq', 3)->delete();   // ほかの管理者が 1 件消した
        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->assertNull($import->fresh()->undone_at, '件数が変わっても断る');

        // 一覧を開き直して取り消す。開いたままの古い画面からもう一度送ると断る
        $fresh = $this->undoForm($import);
        $this->actingAs($this->admin)->post($fresh['action'], $fresh['fields']);
        $this->assertSame(2, $import->fresh()->undone_count);
        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertSee('この取り込みはすでに取り消しています。');
        $this->assertSame(2, $import->fresh()->undone_count);
    }

    /** 確かめたあとでほかの管理者が同じ番号を登録していたら（一意の索引）、入れた中身を残してフォームへ戻す（点検 I-4） */
    public function test_a_number_taken_while_saving_is_refused_with_the_input_kept(): void
    {
        $done = false;
        ApprovalPaperRecord::creating(function () use (&$done): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->insert([
                    'department_id' => $this->estate->id, 'fiscal_year' => 2026, 'seq' => 21, 'number' => 'R8-M-021', 'decided_on' => '2026-06-10',
                    'subject' => 'ほかの管理者が登録', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->send($this->storeForm(), $this->fields(), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        $this->assertStringContainsString(e('R8-M-021 の紙の決裁はすでにあります。ＮＯ を確かめてください。'), $html);
        $this->assertStringContainsString('value="丁田"', $html);
        $this->assertSame(0, ApprovalPaperRecord::count(), '差し込んだ行も一緒に戻る');
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    /** 保存のフォームは二度押し止め（1 回目で登録できたのに、2 回目が「すでにあります」で戻らないように。点検 M-1） */
    public function test_the_save_form_is_sent_only_once(): void
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        $form = substr($html, strrpos(substr($html, 0, strpos($html, 'action="' . route('approvals.admin.papers.store') . '"')), '<form'));
        $open = substr($form, 0, strpos($form, '>') + 1);
        foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
            $this->assertStringContainsString($attribute, $open);
        }
        $this->assertMatchesRegularExpression('/<button type="submit" :disabled="submitting"/', $form);
        $this->assertSame(1, substr_count($html, 'function approvalSubmitOnce()'), '二度押し止めの部品がページに 1 回だけ描かれていない');
    }

    /** 壊れた送信は一覧へ戻す（取り込みのコントローラは back() を使わない） */
    public function test_a_broken_undo_goes_back_to_the_list(): void
    {
        [$import] = $this->importedBatch();

        $response = $this->actingAs($this->admin)->from(route('approvals.admin.papers.import.preview'))->post(route('approvals.admin.papers.imports.undo', $import), []);

        $this->assertSame(route('approvals.admin.papers.index'), $response->headers->get('Location'));
        $this->assertSame(4, ApprovalPaperRecord::count());
    }
}
