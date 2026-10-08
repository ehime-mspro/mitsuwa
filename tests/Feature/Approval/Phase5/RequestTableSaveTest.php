<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\SubmitChecker;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\TestCase;

/** ② の保存と提出の条件（明細表・追加の欄・使える部門。要件 5.5・段階5 設計書 §5.5・D2・D3・D13・D15） */
class RequestTableSaveTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;

    /** 9/17 に利用者が見た申請の画面の見本の中身（画面が送る形） */
    private function contractInput(array $w, ApprovalType $type, array $override = []): array
    {
        return array_merge([
            'type_id'       => (string) $type->id,
            'department_id' => (string) $w['dept']->id,
            'subject'       => '山田様請負新築工事契約の件',
            'amount'        => '1',   // 明細表の種類では使わない（サーバーが明細表から計算する。D15）
            'schedule'      => '',
            'body'          => '仕様変更によるオプション工事を含む。',
            'amount_table'  => [
                'upper' => [
                    ['fixed' => '工事請負金額', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                    ['name' => 'オプション工事', 'sale' => '1,200,000', 'cost' => '850,000'],
                    ['fixed' => '紹介料', 'sale' => '0', 'cost' => '300,000'],
                ],
                'lower' => [
                    ['fixed' => '土地契約金額', 'sale' => '12,000,000', 'cost' => '10,500,000'],
                    ['name' => '', 'sale' => '', 'cost' => ''],
                ],
            ],
            'tsubo'         => '３８．５坪',
            'tsubo_price'   => '1,083,000円',
            'staff'         => '佐藤 健一',
            'contract_date' => '2026-10-20',
            'intent'        => 'save',
        ], $override);
    }

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    public function test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();

        $request = ApprovalRequest::sole();
        // 金額は合計金額の販売金額（送った「1」は使わない）
        $this->assertSame(41700000, $request->amount);
        $this->assertSameIgnoringKeyOrder([
            'subtotal' => true,
            'upper'    => [
                $this->row('工事請負金額', true, 28500000, 22000000),
                $this->row('オプション工事', false, 1200000, 850000),
                $this->row('紹介料', true, 0, 300000),
            ],
            'lower' => [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
        ], $request->amount_table);
        $this->assertSame(['38.50', 1083000, '佐藤 健一', '2026-10-20'], [$request->tsubo, $request->tsubo_price, $request->staff, $request->contract_date->format('Y-m-d')]);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $request->fixed_text, '定型文は保存したときに種類から写す');
        $this->assertSame('仕様変更によるオプション工事を含む。', $request->body, '本文は補足');

        // 定型文を変えると、まだ出していない下書きは次に保存したときに変わる（D14）
        $type->update(['fixed_text' => '新しい定型文']);
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->contractInput($w, $type, ['lock_version' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('新しい定型文', $request->fresh()->fixed_text);
    }

    public function test_the_unused_fields_are_not_kept(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w, ['uses_tsubo' => false, 'uses_staff' => false, 'fixed_text' => null]);

        // 5W2H の種類は明細表と追加の欄を持たない（送られても入れない）。金額は打ったもの
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['amount' => '2,850,000']))->assertSessionHasNoErrors();
        $points = ApprovalRequest::sole();
        $this->assertNull($points->amount_table);
        $this->assertSame([2850000, null, null, null, null, null], [$points->amount, $points->tsubo, $points->tsubo_price, $points->staff, $points->contract_date, $points->fixed_text]);

        // 明細表の種類でも、使わない欄は入れない
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();
        $table = ApprovalRequest::where('type_id', $type->id)->sole();
        $this->assertSame([null, 1083000, null, '2026-10-20', null], [$table->tsubo, $table->tsubo_price, $table->staff, $table->contract_date?->format('Y-m-d'), $table->fixed_text]);
    }

    public function test_the_rows_and_the_extras_are_checked_even_for_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);
        $with = fn (array $row) => ['amount_table' => ['upper' => [$row], 'lower' => []]];

        foreach ([
            [$with(['name' => '値引き', 'sale' => 'abc']), 'amount_table.upper.0.sale', '明細表の販売金額「abc」は数で入力してください（マイナスも入れられます）。'],
            [$with(['name' => '値引き', 'cost' => '1,000,000,000,000']), 'amount_table.upper.0.cost', '明細表の工事原価は 12 桁までで入力してください。'],
            [$with(['name' => str_repeat('あ', 31), 'sale' => '1']), 'amount_table.upper.0.name', '明細表の項目名は 30 文字以内で入力してください。'],
            [['amount_table' => ['upper' => array_fill(0, 31, ['name' => 'x']), 'lower' => []]], 'amount_table.upper', '明細表の前半の行は 30 行までです。'],
            [['tsubo' => '38.555'], 'tsubo', '坪数は小数第 2 位までで入力してください。'],
            [['tsubo' => '広い'], 'tsubo', '坪数は数で入力してください（例: 38.5）。'],
            [['tsubo' => '100000'], 'tsubo', '坪数は 99,999.99 以下で入力してください。'],
            [['tsubo_price' => '-1'], 'tsubo_price', '坪単価は 0 以上で入力してください。'],
            [['staff' => str_repeat('あ', 51)], 'staff', '担当者は 50 文字以内で入力してください。'],
            [['contract_date' => '2026-02-30'], 'contract_date', '契約予定日は日付で入力してください。'],
            [['contract_date' => '1999-12-31'], 'contract_date', '契約予定日は 2000 年から 2099 年の日付で入力してください。'],
        ] as [$override, $key, $message]) {
            $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, $override))
                ->assertSessionHasErrors([$key => $message]);
        }
        $this->assertSame(0, ApprovalRequest::count());

        // マイナス（D3）・12 桁ちょうど・小数第 2 位までの坪数・全角のマイナスは通る
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '999,999,999,999', 'cost' => '－３００，０００']], 'lower' => []],
            'tsubo'        => '99999.99',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(-300000, ApprovalRequest::sole()->amount_table['upper'][0]['cost']);
    }

    /** 1 つの入力に矛盾する 2 つのエラー文を出さない（規則の先頭の bail。最終点検の台帳 L103） */
    public function test_one_bad_input_gets_one_error_sentence_only(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);
        $with = fn (array $row) => ['amount_table' => ['upper' => [$row], 'lower' => []]];

        foreach ([
            [['tsubo' => 'abc'], 'tsubo', '坪数は数で入力してください（例: 38.5）。'],
            [['tsubo_price' => 'abc'], 'tsubo_price', '坪単価は円の数で入力してください。'],
            [['contract_date' => 'abc'], 'contract_date', '契約予定日は日付で入力してください。'],
            [$with(['name' => '値引き', 'sale' => str_repeat('9', 20)]), 'amount_table.upper.0.sale', '明細表の販売金額「99999999999999999999」は数で入力してください（マイナスも入れられます）。'],
            [$with(['name' => '値引き', 'cost' => '-' . str_repeat('9', 20)]), 'amount_table.upper.0.cost', '明細表の工事原価「-99999999999999999999」は数で入力してください（マイナスも入れられます）。'],
        ] as [$override, $key, $message]) {
            $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, $override))
                ->assertSessionHasErrors($key);

            $this->assertSame([$message], session('errors')->get($key), "{$key} のエラー文が 1 つでない");
        }
        $this->assertSame(0, ApprovalRequest::count());
    }

    /** 行ごとには上限の中でも、合計が 12 桁を超えると断る（金額の列に入らない・本番の MySQL では 500 になる） */
    public function test_a_total_beyond_twelve_digits_is_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '999999999999'], ['name' => '追加', 'sale' => '1']], 'lower' => []],
        ]))->assertSessionHasErrors(['amount_table' => '明細表の合計が大きすぎます（999,999,999,999 円まで）。']);

        // 工事原価の合計も同じ（点検の T-6）
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '1', 'cost' => '-999999999999'], ['name' => '値引き', 'cost' => '-1']], 'lower' => []],
        ]))->assertSessionHasErrors(['amount_table' => '明細表の合計が大きすぎます（999,999,999,999 円まで）。']);

        $this->assertSame(0, ApprovalRequest::count());
    }

    /** 数の入力は画面と同じ数に読む（先頭の + と 0 を落とす。点検の M-2）。明細表の種類の補足のエラー文は「補足」（M-7） */
    public function test_the_numbers_are_read_like_the_screen_and_the_note_is_named_so(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '+1,000', 'cost' => '0100']], 'lower' => []],
            'tsubo_price'  => '＋1,000円',
        ]))->assertSessionHasNoErrors();
        $request = ApprovalRequest::sole();
        $this->assertSame([1000, 100, 1000], [$request->amount_table['upper'][0]['sale'], $request->amount_table['upper'][0]['cost'], $request->tsubo_price]);

        // 5W2H の種類の金額も同じそろえ方（2a の金額の欄）
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['amount' => '０２,８５０,０００円']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2850000, ApprovalRequest::where('type_id', $w['type']->id)->sole()->amount);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, ['body' => str_repeat('あ', 20001)]))
            ->assertSessionHasErrors('body');
        $this->assertStringStartsWith('補足', session('errors')->first('body'));
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['body' => str_repeat('あ', 20001)]))
            ->assertSessionHasErrors('body');
        $this->assertStringStartsWith('重点ポイント（5W2H）', session('errors')->first('body'));
    }

    /** 明細表の種類の提出に要るもの（D2）: 合計金額の販売金額（0 円より大きい）・担当者・契約予定日・名前のない行の金額には項目名 */
    public function test_a_table_request_needs_the_total_the_staff_and_the_contract_date(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $empty = $this->draftFor($w, ['type_id' => $type->id, 'body' => null, 'amount' => null]);
        $this->assertSame([
            '明細表の合計金額の販売金額を入れてください（0 円より大きい金額）。',
            '担当者を入力してください。',
            '契約予定日を入力してください。',
        ], SubmitChecker::reasons($empty, $w['applicant']), '補足（本文）は空でもよい・坪数と坪単価も空でもよい');

        $unnamed = $this->draftFor($w, ['type_id' => $type->id, 'staff' => '　', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => true,
            'upper'    => [$this->row('工事請負金額', true, 1000, null), $this->row(null, false, null, -50)],
            'lower'    => [],
        ]]);
        $this->assertSame([
            '明細表の名前のない行に金額が入っています。項目名を入れてください。',
            '担当者を入力してください。',
        ], SubmitChecker::reasons($unnamed, $w['applicant']), '空白だけの担当者は空');

        $suffixOnly = $this->draftFor($w, ['type_id' => $type->id, 'subject' => '様請負新築工事契約の件', 'staff' => '佐藤', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, null)], 'lower' => [],
        ]]);
        $this->assertSame(
            ['件名の前半（「様請負新築工事契約の件」の前）を入力してください。'],
            SubmitChecker::reasons($suffixOnly, $w['applicant']),
            '決まり文句だけの件名は件名が無いのと同じ（点検の I-4）'
        );

        // そろえば画面から提出できる
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, ['intent' => 'submit']))
            ->assertSessionHasNoErrors();
        $this->assertSame(ApprovalStatus::HeadReview, ApprovalRequest::where('subject', '山田様請負新築工事契約の件')->sole()->status);
    }

    /** 5W2H の種類でも、担当者・契約予定日を使う設定なら提出に要る（D2 × D12。点検の T-5） */
    public function test_a_points_type_that_uses_the_staff_and_the_date_needs_them(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['type']->update(['uses_staff' => true, 'uses_contract_date' => true]);

        $draft = $this->draftFor($w);
        $this->assertSame(['担当者を入力してください。', '契約予定日を入力してください。'], SubmitChecker::reasons($draft, $w['applicant']));

        $draft->update(['staff' => '佐藤 健一', 'contract_date' => '2026-10-20']);
        $this->assertSame([], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    public function test_the_types_offered_are_those_usable_in_my_departments(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other    = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産']);
        $mine     = $this->housingContractType($w, ['name' => '住宅の契約用']);
        $mine->departments()->attach($w['dept']->id);
        $elsewhere = $this->housingContractType($w, ['name' => '不動産だけの種類']);
        $elsewhere->departments()->attach($other->id);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $this->assertStringContainsString('>' . e($w['type']->name) . '</option>', $html, '使える部門の無い種類は全部門');
        $this->assertStringContainsString('>住宅の契約用</option>', $html);
        $this->assertStringNotContainsString('不動産だけの種類', $html);

        // 自分の部門で使えない種類は保存でも断る
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $elsewhere))
            ->assertSessionHasErrors(['type_id' => '選んだ申請の種類は使えません。選び直してください。']);

        // あとで使える部門から外れた種類の下書きは、編集の画面でその種類を選択肢に残す（保存で消えない。§5.5。点検の T-4）
        $draft = $this->draftFor($w, ['type_id' => $elsewhere->id]);
        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $draft))->assertOk()->assertSee('>不動産だけの種類</option>', false);
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $draft), $this->contractInput($w, $elsewhere, ['lock_version' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertSame($elsewhere->id, $draft->fresh()->type_id);
    }

    /** 兼務の人が、種類を使えない部門で出そうとしたら断る（D13） */
    public function test_a_type_must_be_usable_in_the_chosen_department(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);
        $type = $this->housingContractType($w);
        $type->departments()->attach($other->id);

        $draft = $this->draftFor($w, ['type_id' => $type->id, 'staff' => '佐藤', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, null)], 'lower' => [],
        ]]);

        $this->assertSame(
            ['申請の種類「住宅の契約用（請負新築工事契約）」は申請部門「住宅事業部」では使えません。種類か申請部門を選び直してください。'],
            SubmitChecker::reasons($draft, $w['applicant'])
        );
        $draft->update(['department_id' => $other->id]);
        $this->assertSame([], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    /** 差戻し中は、種類と申請部門を前の提出から変えていなければ、使える部門が変わってもそのまま出し直せる（D13） */
    public function test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);

        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '金額の根拠を足してください');
        $w['type']->departments()->attach($other->id);   // 回っている途中で、管理者が使える部門をミツワ不動産だけにした

        $returned = $request->fresh();
        $this->assertSame(ApprovalStatus::Returned, $returned->status);
        $this->assertSame([], SubmitChecker::reasons($returned, $w['applicant']), '種類も部門も変えていなければ出し直せる');

        // 種類か部門を変えたら、今の決まりに従う
        $returned->update(['department_id' => $other->id]);
        $this->assertSame([], SubmitChecker::reasons($returned->fresh(), $w['applicant']), 'ミツワ不動産では使える');
        $back = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($back->id);
        $returned->update(['department_id' => $back->id]);
        $this->assertStringContainsString('では使えません。', implode('', SubmitChecker::reasons($returned->fresh(), $w['applicant'])));

        // 部門を戻しても、種類を変えたら今の決まりに従う（点検の T-4）
        $elsewhere = $this->housingContractType($w, ['name' => '不動産だけの種類']);
        $elsewhere->departments()->attach($other->id);
        $returned->update(['department_id' => $w['dept']->id, 'type_id' => $elsewhere->id]);
        $this->assertContains(
            '申請の種類「不動産だけの種類」は申請部門「住宅事業部」では使えません。種類か申請部門を選び直してください。',
            SubmitChecker::reasons($returned->fresh(), $w['applicant'])
        );
    }

    public function test_copying_a_table_request_copies_the_rows_and_the_extras(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();
        $source = ApprovalRequest::sole();

        $copy = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->viewData('approvalRequest');

        $this->assertSame($type->id, $copy->type_id);
        $this->assertSame($source->amount_table, $copy->amount_table);
        $this->assertSame(['38.50', 1083000, '佐藤 健一', '2026-10-20'], [$copy->tsubo, $copy->tsubo_price, $copy->staff, $copy->contract_date->format('Y-m-d')]);
        $this->assertNull($copy->fixed_text, '定型文は保存のときに種類から写す');
        $this->assertSame(1, ApprovalRequest::count(), '保存するまで下書きはできない');

        // あとで種類が自分の部門で使えなくなったら、写すときに種類を空にして選び直してもらう（点検の T-4）
        $type->departments()->attach($this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産'])->id);
        $later = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->viewData('approvalRequest');
        $this->assertNull($later->type_id);
        $this->assertSame($source->amount_table, $later->amount_table, '明細表は写す（選び直した種類の行に並べ直す）');
    }
}
