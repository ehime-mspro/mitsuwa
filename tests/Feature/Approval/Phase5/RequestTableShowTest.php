<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalType;
use App\Support\Approval\RequestSnapshot;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** ③ 詳細・控え・変更点・提出の履歴の明細表と追加の欄（要件 4.4・5.5.4・5.5.5・段階5 設計書 §5.6・D14） */
class RequestTableShowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出した申請（部門長確認中） */
    private function submittedContract(array $w, ApprovalType $type, array $attributes = []): ApprovalRequest
    {
        return $this->submittedFor($w, array_merge([
            'type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'body' => '仕様変更によるオプション工事を含む。',
            'amount' => 41700000, 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '佐藤 健一', 'contract_date' => '2026-10-20',
            'fixed_text' => '上記の内容に基づき、販売をおこないます。',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => [
                    $this->row('工事請負金額', true, 28500000, 22000000),
                    $this->row('オプション工事', false, 1200000, 850000),
                    $this->row('紹介料', true, 0, 300000),
                ],
                'lower' => [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
            ],
        ], $attributes));
    }

    /** 申請の中身の欄の文字（タグの境目を空白にしてタグを除き、空白を詰めたもの） */
    private function contentText(string $html): string
    {
        $start = strpos($html, '>申請の中身</h2>');
        $end   = strpos($html, '</section>', $start);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', substr($html, $start, $end - $start))), ENT_QUOTES, 'UTF-8')));
    }

    public function test_the_detail_shows_the_table_the_extras_the_fixed_text_and_the_supplement(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));

        foreach ([$w['applicant'], $w['head']] as $viewer) {
            $text = $this->contentText($this->actingAs($viewer)->get(route('approvals.requests.show', $request))->assertOk()->getContent());

            $this->assertStringContainsString('金額（税抜） 41,700,000円', $text);
            $this->assertStringContainsString(
                '金額の明細 項目 販売金額 工事原価 粗利益金額 粗利率'
                . ' 工事請負金額 販売金額 28,500,000円 工事原価 22,000,000円 粗利益金額 6,500,000円 粗利率 22.8%'
                . ' オプション工事 販売金額 1,200,000円 工事原価 850,000円 粗利益金額 350,000円 粗利率 29.2%'
                . ' 紹介料 販売金額 0円 工事原価 300,000円 粗利益金額 -300,000円 粗利率 —'
                . ' 計 販売金額 29,700,000円 工事原価 23,150,000円 粗利益金額 6,550,000円 粗利率 22.1%'
                . ' 土地契約金額 販売金額 12,000,000円 工事原価 10,500,000円 粗利益金額 1,500,000円 粗利率 12.5%'
                . ' 合計金額 販売金額 41,700,000円 工事原価 33,650,000円 粗利益金額 8,050,000円 粗利率 19.3%'
                . ' 坪数 38.5坪 坪単価 1,083,000円 担当者 佐藤 健一 契約予定日 2026/10/20'
                . ' 上記の内容に基づき、販売をおこないます。'
                . ' 補足 仕様変更によるオプション工事を含む。',
                $text,
                '明細表 → 追加の欄 → 定型文 → 補足の順（名前も金額も無い自由行は出さない）'
            );
            $this->assertStringNotContainsString('重点ポイント', $text);
        }
    }

    /** 控えには、明細表・提出したときに使っていた追加の欄・定型文が入る。あとで種類の設定を変えても、ほかの人の見る中身は変わらない（D14） */
    public function test_the_snapshot_keeps_what_was_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type);

        $snapshot = ApprovalRevision::where('request_id', $request->id)->sole()->snapshot;
        $this->assertSame([28500000, 22000000], [$snapshot['amount_table']['upper'][0]['sale'], $snapshot['amount_table']['upper'][0]['cost']]);
        $this->assertTrue($snapshot['amount_table']['subtotal']);
        $this->assertEqualsCanonicalizing(['tsubo_price', 'staff', 'contract_date'], array_keys($snapshot['extras']), '使っていない坪数は控えない');
        $this->assertSame('2026-10-20', $snapshot['extras']['contract_date']);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $snapshot['fixed_text']);

        $type->update(['fixed_text' => '新しい定型文', 'uses_staff' => false, 'table_layout' => ['subtotal' => false, 'upper' => ['別の名前'], 'lower' => []]]);
        $text = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('担当者 佐藤 健一', $text);
        $this->assertStringContainsString('上記の内容に基づき、販売をおこないます。', $text);
        $this->assertStringContainsString(' 計 販売金額 29,700,000円', $text, '提出した申請は「計」の有無も提出したときのまま');
        $this->assertStringNotContainsString('新しい定型文', $text);
    }

    /** 差戻し中に申請者が明細表を直していても、ほかの人には最後に提出した明細表を出す */
    public function test_others_see_the_submitted_table_while_the_applicant_edits_it(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '工事原価の根拠を');
        $edited = $request->fresh()->amount_table;
        $edited['upper'][0]['sale'] = 30000000;
        $request->fresh()->update(['amount_table' => $edited, 'staff' => '田中 次郎']);

        $others = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('工事請負金額 販売金額 28,500,000円', $others);
        $this->assertStringContainsString('担当者 佐藤 健一', $others);

        $own = $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('工事請負金額 販売金額 30,000,000円', $own);
        $this->assertStringContainsString('担当者 田中 次郎', $own);
    }

    /** 出し直した申請の変更点に、明細表の行（変わった欄・増えた行）と追加の欄が出る（要件 4.4） */
    public function test_the_changes_show_the_rows_and_the_extras_that_changed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '工事原価の根拠を');

        $table = $request->fresh()->amount_table;
        $table['upper'][0]['sale'] = 29000000;
        $table['upper'][]          = $this->row('追加工事', false, 500000, 400000);
        $request->fresh()->update(['amount_table' => $table, 'staff' => '田中 次郎', 'amount' => 42700000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $changes   = RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot);
        $this->assertSame([
            ['label' => '金額（税抜）', 'before' => '41,700,000円', 'after' => '42,700,000円'],
            ['label' => '担当者', 'before' => '佐藤 健一', 'after' => '田中 次郎'],
        ], $changes['fields']);
        $this->assertSame([['upper', 'changed', ['sale']], ['upper', 'added', []]], array_map(fn (array $r) => [$r['section'], $r['kind'], $r['changed']], $changes['table']));
        $this->assertTrue(RequestSnapshot::hasChanges($changes));

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('明細表の前回との違い（＋ 増えた行・− 消えた行・変わった欄は前 → 後）', $html);
        // スマホでは 1 行 1 枚のカード（横スクロールにしない。要件 5.5.6。点検の M-9）
        $this->assertStringContainsString('<table class="block md:table w-full border-collapse text-[12px]">', $html);
        $this->assertStringNotContainsString('min-w-[560px]', $html);
        $this->assertMatchesRegularExpression('/<span class="sr-only">前: <\/span>28,500,000円<\/span>\s*<span[^>]*>→<\/span>\s*<span class="font-semibold text-emerald-800"><span class="sr-only">後: <\/span>29,000,000円<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/<span class="sr-only">増えた行<\/span>.*?追加工事.*?500,000円.*?400,000円/su', $html);

        // 明細表の行が同じなら明細表の違いは出ない
        $same = RequestSnapshot::changes($revisions[1]->snapshot, $revisions[1]->snapshot);
        $this->assertNull($same['table']);
        $this->assertFalse(RequestSnapshot::hasChanges($same));
    }

    /** 真ん中の自由行を空にして出し直しても、変わっていない行は「消えた」と出ない（行の鍵〈名前を設定した行か・項目名〉で合わせる。点検の I-2） */
    public function test_the_changes_match_the_rows_by_their_names(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $table = $request->fresh()->amount_table;
        $table['upper'][1]         = $this->row(null, false, null, null);        // オプション工事の行を空にした（詳細に出ない行になる）
        $table['upper'][2]['cost'] = 350000;                                     // 紹介料の工事原価を直した
        $table['lower'][1]         = $this->row('造成', false, 800000, 700000);  // 後半の空の自由行に書いた
        $request->fresh()->update(['amount_table' => $table, 'amount' => 41300000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $summary   = fn (array $changes): array => array_map(fn (array $r) => [$r['section'], $r['kind'], ($r['after'] ?? $r['before'])['name'], $r['changed']], $changes['table'] ?? []);
        $this->assertSame([
            ['upper', 'removed', 'オプション工事', []],
            ['upper', 'changed', '紹介料', ['cost']],
            ['lower', 'added', '造成', []],
        ], $summary(RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot)));

        // 項目名を直した自由行は、消えた行と増えた行で出る
        $renamed = $revisions[1]->snapshot;
        $renamed['amount_table']['lower'][1]['name'] = '造成工事';
        $this->assertSame([['lower', 'removed', '造成', []], ['lower', 'added', '造成工事', []]], $summary(RequestSnapshot::changes($revisions[1]->snapshot, $renamed)));
    }

    /** 明細表の工事原価だけを直して出し直しても（金額は変わらない）、変更点が出る（要件 4.4） */
    public function test_a_change_of_only_the_table_is_shown_as_a_change(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '土地の原価を確かめてください');

        $table = $request->fresh()->amount_table;
        $table['lower'][0]['cost'] = 10000000;
        $request->fresh()->update(['amount_table' => $table]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $changes   = RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot);
        $this->assertSame([[], null], [$changes['fields'], $changes['body']], '金額・追加の欄・補足は変わっていない');
        $this->assertTrue(RequestSnapshot::hasChanges($changes));
        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->assertSee('明細表の前回との違い');
    }

    public function test_the_history_shows_the_table_of_each_round(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $table = $request->fresh()->amount_table;
        $table['upper'][0]['sale'] = 29000000;
        $request->fresh()->update(['amount_table' => $table, 'amount' => 42200000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $html    = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent();
        $history = substr($html, strpos($html, '>提出の履歴</h2>'));
        $this->assertSame(1, substr_count($history, '28,500,000円'), '1 回目の明細表');
        $this->assertGreaterThanOrEqual(1, substr_count($history, '29,000,000円'), '2 回目の明細表');
    }

    /** 差戻しのあと申請者が明細表か追加の欄だけを直しても「直し始めた」に数える（差戻しの取り消しを断る。2b の D3） */
    public function test_an_edit_of_only_the_table_or_the_extras_counts_as_editing_since_the_return(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '何も直していない');

        $table = $request->fresh()->amount_table;
        $table['lower'][0]['cost'] = 10000000;
        $request->fresh()->update(['amount_table' => $table]);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '明細表を直した');

        $table['lower'][0]['cost'] = 10500000;
        $request->fresh()->update(['amount_table' => $table]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '元に戻した');

        $request->fresh()->update(['contract_date' => '2026-11-01']);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '契約予定日を直した');
    }

    /** 差戻しのあと管理者が種類の「使う」を変えただけなら、申請者が何も直していないので「直し始めた」にしない（取り消せる。点検の M-1） */
    public function test_a_change_of_the_type_settings_is_not_an_edit_since_the_return(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type, ['tsubo' => null]);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $type->update(['uses_tsubo' => true]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '使う欄を足しただけ');
        $type->update(['uses_tsubo' => false, 'uses_tsubo_price' => false]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '使う欄を外しただけ');

        $request->fresh()->update(['tsubo_price' => 2000000]);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '値を直せば直した');
    }

    /** 提出したあとで種類に欄を足しても、申請者本人の詳細にもほかの人と同じ欄だけが出る（D14。点検の M-5） */
    public function test_the_applicant_sees_the_submitted_extras_after_the_submission(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type, ['tsubo' => null]);
        $type->update(['uses_tsubo' => true]);

        $own    = $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $others = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $this->assertStringContainsString('坪単価 1,083,000円', $own);
        $this->assertStringNotContainsString('坪数', $own, '回覧中は提出したときの欄');
        $this->assertStringNotContainsString('坪数', $others);

        // 差戻しで直せるようになったら、本人には種類が今使う欄が出る（これから入れる欄）
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '坪数も入れてください');
        $this->assertStringContainsString('坪数', $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->getContent()));
    }

    /** 段階5 より前の控え（明細表・追加の欄・定型文のキーが無い）は今までどおり 5W2H の形で出す */
    public function test_an_old_snapshot_is_shown_as_before(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request  = $this->submittedFor($w);
        $revision = ApprovalRevision::where('request_id', $request->id)->sole();
        $old      = array_diff_key($revision->snapshot, array_flip(['amount_table', 'extras', 'fixed_text']));
        DB::table('approval_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($old, JSON_UNESCAPED_UNICODE)]);

        $text = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $this->assertStringContainsString('重点ポイント（5W2H） ■ なぜ（目的・理由） ・老朽化のため', $text);
        $this->assertStringNotContainsString('金額の明細', $text);
    }

    public function test_the_names_in_the_table_are_escaped(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['fixed_text' => '<b>定型</b>']);
        $request = $this->submittedFor($w, [
            'type_id' => $type->id, 'staff' => '<i>担当</i>', 'contract_date' => '2026-10-20', 'fixed_text' => '<b>定型</b>',
            'amount_table' => ['subtotal' => false, 'upper' => [$this->row('<script>alert(1)</script>', false, 1000, null)], 'lower' => []],
        ]);

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(e('<script>alert(1)</script>'), $html);
        $this->assertStringContainsString(e('<i>担当</i>'), $html);
        $this->assertStringContainsString(e('<b>定型</b>'), $html);
    }
}
