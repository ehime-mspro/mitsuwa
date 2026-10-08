<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalBodyForm;
use App\Models\ApprovalSettingLog;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\TestCase;

/** ⑨ 申請の種類の段階5 の欄（要件 5.5.1・5.5.7・段階5 設計書 §5.4・D4・D12・D13） */
class TypeSettingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;

    /** 要件 5.5.7 の「請負新築工事契約」の設定 */
    private function contractFields(array $w, array $override = []): array
    {
        return array_merge([
            'name'                 => '住宅の契約用（請負新築工事契約）',
            'review_department_id' => (string) $w['reviewDept']->id,
            'sort_order'           => '2',
            'is_active'            => '1',
            'body_form'            => 'table',
            'headings'             => BodyTemplate::DEFAULT,
            'layout_upper'         => ['工事請負金額', '', '紹介料'],
            'layout_lower'         => ['土地契約金額', ''],
            'layout_subtotal'      => '1',
            'subject_suffix'       => '様請負新築工事契約の件',
            'uses_tsubo'           => '1',
            'uses_tsubo_price'     => '1',
            'uses_staff'           => '1',
            'uses_contract_date'   => '1',
            'fixed_text'           => '上記の内容に基づき、販売をおこないます。',
            'department_ids'       => [(string) $w['dept']->id],
        ], $override);
    }

    private function store(User $admin, array $fields)
    {
        return $this->actingAs($admin)->from(route('approvals.admin.types.index'))->post(route('approvals.admin.types.store'), $fields);
    }

    public function test_the_two_settings_of_the_requirements_can_be_made(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);

        // 請負新築工事契約（要件 5.5.7 の 1 つ目）
        $this->store($admin, $this->contractFields($w, ['department_ids' => [(string) $w['dept']->id, (string) $other->id]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('approvals.admin.types.index'));

        $type = ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole();
        $this->assertSame(ApprovalBodyForm::Table, $type->body_form);
        $this->assertTrue($type->usesTable());
        $this->assertSameIgnoringKeyOrder(['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]], $type->table_layout);
        $this->assertSame('様請負新築工事契約の件', $type->subject_suffix);
        $this->assertTrue($type->uses_tsubo && $type->uses_tsubo_price && $type->uses_staff && $type->uses_contract_date);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $type->fixed_text);
        $this->assertEqualsCanonicalizing([$w['dept']->id, $other->id], $type->departments->pluck('id')->all());

        // 追加・少額工事契約（2 つ目。「計」なし・後半なし・間の 5 行は自由行）
        $this->store($admin, $this->contractFields($w, [
            'name' => '住宅の契約用（追加・少額工事契約）', 'layout_upper' => ['工事請負金額', '', '', '', '', ''],
            'layout_lower' => [], 'layout_subtotal' => null, 'subject_suffix' => '様追加・少額工事契約の件',
        ]))->assertSessionHasNoErrors();

        $small = ApprovalType::where('name', '住宅の契約用（追加・少額工事契約）')->sole();
        $this->assertSameIgnoringKeyOrder(['subtotal' => false, 'upper' => ['工事請負金額', null, null, null, null, null], 'lower' => []], $small->table_layout);
    }

    public function test_a_points_type_keeps_no_layout_and_needs_the_headings(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        // 5W2H の種類は行を送っても持たない
        $this->store($admin, $this->contractFields($w, ['name' => '購入', 'body_form' => 'points']))->assertSessionHasNoErrors();
        $this->assertNull(ApprovalType::where('name', '購入')->sole()->table_layout);

        // 5W2H の種類は見出しが要る・明細表の種類は要らない（今の見出しのまま持つ）
        $this->store($admin, $this->contractFields($w, ['name' => '人事', 'body_form' => 'points', 'headings' => '']))
            ->assertSessionHasErrors(['headings' => '見出しを入力してください。']);
        $this->store($admin, $this->contractFields($w, ['headings' => '']))->assertSessionHasNoErrors();
        $this->assertSame(BodyTemplate::DEFAULT, ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole()->headings);

        // 明細表の種類では、隠れた見出しの欄に「■」の形でない文が残っていても断らず、見出しも変えない（点検の M-4）
        $this->store($admin, $this->contractFields($w, ['name' => '住宅の契約用（追加）', 'headings' => '自由な文']))->assertSessionHasNoErrors();
        $this->assertSame(BodyTemplate::DEFAULT, ApprovalType::where('name', '住宅の契約用（追加）')->sole()->headings);
        $this->store($admin, $this->contractFields($w, ['name' => '人事', 'body_form' => 'points', 'headings' => '自由な文']))
            ->assertSessionHasErrors(['headings' => '見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。']);
    }

    public function test_the_layout_is_checked(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        foreach ([
            [['layout_upper' => []], 'layout_upper', '明細表の前半の行を 1 行以上入れてください（名前を空にした行は自由行）。'],
            [['layout_upper' => ['', '　']], 'layout_upper', null],   // 自由行だけでもよい
            [['layout_upper' => ['紹介料', '', '紹介料']], 'layout_upper', '明細表の前半に同じ名前の行があります（紹介料）。'],
            [['layout_lower' => ['土地', ' 土地 ']], 'layout_lower', '明細表の後半に同じ名前の行があります（土地）。'],
            [['layout_upper' => [str_repeat('あ', 31)]], 'layout_upper.0', '明細表の行の名前は30文字以内で入力してください。'],
            [['layout_upper' => array_fill(0, 21, '')], 'layout_upper', '明細表の前半の行は20行までです。'],
            [['subject_suffix' => str_repeat('あ', 51)], 'subject_suffix', null],
            [['fixed_text' => str_repeat('あ', 501)], 'fixed_text', null],
            [['department_ids' => ['999999']], 'department_ids.0', null],
            [['body_form' => 'other'], 'body_form', null],
        ] as $i => [$override, $key, $message]) {
            $response = $this->store($admin, $this->contractFields($w, ['name' => "種類{$i}"] + $override));
            if ($override === ['layout_upper' => ['', '　']]) {
                $response->assertSessionHasNoErrors();

                continue;
            }
            $message === null ? $response->assertSessionHasErrors($key) : $response->assertSessionHasErrors([$key => $message]);
        }

        // 20 行ちょうど・名前 30 文字ちょうど・決まり文句 50 文字・定型文 500 文字は通る
        $this->store($admin, $this->contractFields($w, [
            'name' => 'ちょうど', 'layout_upper' => array_merge([str_repeat('あ', 30)], array_fill(0, 19, '')),
            'subject_suffix' => str_repeat('あ', 50), 'fixed_text' => str_repeat('あ', 500),
        ]))->assertSessionHasNoErrors();
    }

    /** 申請（下書きを含む）がある種類は本文の形を切り替えられない（D4）。無ければ切り替えられ、明細表をやめると行は持たない */
    public function test_the_body_form_cannot_change_once_the_type_has_a_request(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $put   = fn (array $fields) => $this->actingAs($admin)->from(route('approvals.admin.types.index'))
            ->put(route('approvals.admin.types.update', $w['type']), $this->contractFields($w, ['name' => $w['type']->name] + $fields));

        $draft = $this->draftFor($w);
        $put([])->assertSessionHasErrors(['body_form' => 'この種類の申請が 1 件あるため、本文の形を変えられません。変えたいときは新しい種類を作り、この種類を「停止」にしてください。']);
        $this->assertSame(ApprovalBodyForm::Points, $w['type']->fresh()->body_form);
        $this->assertSame(0, ApprovalSettingLog::count(), '断ったら何も記録しない');

        // 押せない欄は送られない＝今の形のまま（ほかの欄は保存できる）
        $fields = $this->contractFields($w, ['name' => $w['type']->name]);
        unset($fields['body_form']);
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $fields)->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Points, $w['type']->fresh()->body_form);
        $this->assertSame('様請負新築工事契約の件', $w['type']->fresh()->subject_suffix);

        // 申請が無くなれば切り替えられる
        $draft->delete();
        $put([])->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Table, $w['type']->fresh()->body_form);

        // 明細表の種類でも、押せない本文の形が送られなければ今の形のまま（5W2H として扱うと、申請のある明細表の種類を保存できない）
        $tableDraft = $this->draftFor($w);
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $fields)->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Table, $w['type']->fresh()->body_form);
        $tableDraft->delete();
        $put(['body_form' => 'points'])->assertSessionHasNoErrors();
        $this->assertNull($w['type']->fresh()->table_layout, '5W2H に戻すと行は持たない');
    }

    public function test_the_changes_are_logged_with_the_usable_departments(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $base  = [
            'name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1', 'is_active' => '1',
        ];

        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $base + [
            'uses_staff' => '1', 'subject_suffix' => '　', 'department_ids' => [(string) $w['dept']->id, (string) $w['dept']->id],
        ])->assertSessionHasNoErrors();

        $log = ApprovalSettingLog::where('action', 'type.updated')->sole();
        // 変わった項目だけ（空白だけの決まり文句は空のまま・同じ部門を 2 回送っても 1 つ）
        $this->assertEquals(['uses_staff' => true, 'department_ids' => [$w['dept']->id]], $log->new_values);
        $this->assertEquals(['uses_staff' => false, 'department_ids' => []], $log->old_values);
        $this->assertNull($w['type']->fresh()->subject_suffix);

        // 同じ中身で保存し直しても記録は増えない（明細表の行の並びなども比べる）
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $base + ['uses_staff' => '1', 'department_ids' => [(string) $w['dept']->id]]);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.updated')->count());
    }

    public function test_the_page_shows_the_form_and_the_departments_and_passes_the_values_to_the_modal(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->store($admin, $this->contractFields($w))->assertSessionHasNoErrors();
        $type = ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole();
        $this->draftFor($w, ['type_id' => $type->id]);

        $html = $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertOk()->getContent();

        // 一覧: 本文の形と使える部門
        $this->assertMatchesRegularExpression('/金額の明細表\s*<span class="block text-\[11px\] text-gray-500">住宅事業部<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/5W2H の見出し\s*<span class="block text-\[11px\] text-gray-500">全部門<\/span>/u', $html);

        // 編集の小窓に渡す値（申請が 1 件ある＝本文の形を押せない）
        preg_match_all("/openEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }
        $this->assertSame('table', $rows[$type->id]['body_form']);
        $this->assertSameIgnoringKeyOrder(['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]], $rows[$type->id]['table_layout']);
        $this->assertSame([$w['dept']->id], $rows[$type->id]['department_ids']);
        $this->assertSame(1, $rows[$type->id]['requests_count']);
        $this->assertTrue($rows[$type->id]['uses_contract_date']);

        // 追加と編集の小窓の欄（同じ部品を 2 回。x-model と送る名前を対で固定する）
        foreach (['create', 'edit'] as $state) {
            foreach ([
                'name="body_form" value="points" x-model="' . $state . '.bodyForm" :disabled="' . $state . '.locked"',
                'name="body_form" value="table" x-model="' . $state . '.bodyForm" :disabled="' . $state . '.locked"',
                '<template x-for="(row, index) in ' . $state . '.upper" :key="row.key">',
                '<template x-for="(row, index) in ' . $state . '.lower" :key="row.key">',
                'name="layout_subtotal" value="1" x-model="' . $state . '.subtotal"',
                'name="subject_suffix" x-model="' . $state . '.suffix"',
                'name="uses_tsubo" value="1" x-model="' . $state . '.uses.tsubo"',
                'name="uses_contract_date" value="1" x-model="' . $state . '.uses.contract_date"',
                'name="fixed_text" x-model="' . $state . '.fixedText"',
                'name="department_ids[]" value="' . $w['dept']->id . '" x-model="' . $state . '.departmentIds"',
                '<div x-show="' . $state . '.bodyForm === \'points\'">',
            ] as $expected) {
                $this->assertStringContainsString($expected, $html);
            }
        }
        $this->assertSame(2, substr_count($html, 'name="layout_upper[]" x-model="row.name"'));
        // 追加の小窓は 5W2H の種類から始まる（フォームの往復で送られる）
        $this->assertStringContainsString('name="body_form" value="points" x-model="create.bodyForm" :disabled="create.locked" checked>', $html);
        $this->assertStringContainsString('this.edit = stateFrom(row);', $html);
    }
}
