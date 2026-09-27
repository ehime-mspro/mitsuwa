<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalSettingLog;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/** 申請種類の管理（画面⑨・設計書 §5.5） */
class TypeManagementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertOk()->getContent();
    }

    public function test_the_list_shows_the_review_department_the_state_and_the_count(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);
        $w['type']->update(['is_active' => false]);

        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString($w['type']->name, $html);
        $this->assertStringContainsString('総務部', $html);
        $this->assertStringContainsString('停止', $html);
        $this->assertStringContainsString('1 件', $html);
        // 小窓を動かす部品（@push('scripts') の中身）が描かれていること（LayoutScriptStackTest と同じ見方）
        $this->assertStringContainsString('function approvalTypes()', $html);
    }

    /** 描いた追加のフォームをそのまま送り返す（見出しの初期値は標準の 6 つ） */
    public function test_a_type_can_be_created_from_the_rendered_form(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.types.store') . '"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertSame(BodyTemplate::DEFAULT, $form['fields']['headings']);
        $this->assertSame('1', $form['fields']['is_active']);

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'name' => '人事', 'review_department_id' => (string) $w['reviewDept']->id,
        ]))->assertRedirect(route('approvals.admin.types.index'));

        $type = ApprovalType::where('name', '人事')->sole();
        $this->assertTrue($type->is_active);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.created')->count());
    }

    /** チェックを外して保存すると停止（送られないチェックボックス） */
    public function test_a_type_can_be_stopped(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->put(route('approvals.admin.types.update', $w['type']), [
            'name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1',
        ])->assertRedirect(route('approvals.admin.types.index'));

        $this->assertFalse($w['type']->fresh()->is_active);
        $this->assertSame(['is_active' => false], ApprovalSettingLog::where('action', 'type.updated')->sole()->new_values);
    }

    public function test_the_name_is_unique_and_a_review_department_is_required(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.types.store'), [
            'name' => $w['type']->name, 'headings' => 'x', 'review_department_id' => '', 'sort_order' => '0', 'is_active' => '1',
        ])->assertSessionHasErrors([
            'name' => 'この種類名は既に登録されています。',
            'review_department_id' => '審査部門を選択してください。',
        ]);
    }

    /** 見出しは「■」の行と中身の無い「・」の行だけ（ほかの形だと、本文が見出しのままでも提出できてしまう。D12） */
    public function test_the_headings_must_be_in_the_heading_form(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        foreach (["【なぜ】\n・\n【何を】\n・", "■ なぜ\n- \n■ 何を\n- ", "1. 目的\n・\n2. 内容\n・"] as $headings) {
            $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), [
                'name' => $w['type']->name, 'headings' => $headings,
                'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1', 'is_active' => '1',
            ])->assertSessionHasErrors(['headings' => '見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。']);
        }

        $this->assertSame(BodyTemplate::DEFAULT, $w['type']->fresh()->headings);
    }

    public function test_a_type_with_requests_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.types.destroy', $w['type']))
            ->assertSessionHas('error', 'この種類の申請が 1 件あるため削除できません。使わなくなった種類は「停止」にしてください。');

        $this->assertNotNull($w['type']->fresh());
    }

    public function test_an_unused_type_can_be_deleted(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.types.destroy', $w['type']))
            ->assertSessionHas('success', '申請の種類を削除しました。');

        $this->assertNull($w['type']->fresh());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.deleted')->count());
    }

    /** 決裁の管理者でない人は開けない（管理の門番。全ルートの確かめは ApprovalAdminGateTest） */
    public function test_someone_who_is_not_an_admin_gets_403(): void
    {
        $this->actingAs($this->baseUser())->get(route('approvals.admin.types.index'))->assertForbidden();
    }
}
