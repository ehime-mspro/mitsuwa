<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalSettingLog;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /** 表の行ごとに、セルの文字（タグを除いて空白を詰めたもの）と行の HTML */
    private function tableRows(string $html): array
    {
        preg_match_all('/<tr class="hover:bg-gray-50">(.*?)<\/tr>/s', $html, $rows);

        return array_map(function (string $row): array {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);

            return [
                'cells' => array_map(
                    fn (string $c): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'))),
                    $cells[1]
                ),
                'html' => $row,
            ];
        }, $rows[1]);
    }

    /** 編集のボタンが小窓へ渡す値（Js::from は日本語を \u で符号化するので、HTML の文字列では探さない） */
    private function editRows(string $html): array
    {
        preg_match_all("/openEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }

        return $rows;
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

    /** 表のセルそのものを見る（審査部門の名前は選択肢に、「停止」は説明文にも出るので、HTML 全体では探さない） */
    public function test_the_table_cells_show_each_type_in_display_order(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);
        $w['type']->update(['sort_order' => 7]);
        $this->approvalType($w['dept'], ['name' => 'R&D <試行>', 'sort_order' => 0, 'is_active' => false]);
        $company = $w['company']->name;

        $rows = $this->tableRows($this->indexHtml($this->approvalAdmin()));

        $this->assertSame([
            ['R&D <試行>', "{$company}・住宅事業部", '停止', '0 件', '0', '編集 | 削除'],
            [$w['type']->name, "{$company}・総務部", '利用中', '1 件', '7', '編集 | 削除'],
        ], array_column($rows, 'cells'));

        // バッジの色（停止は 6.87:1。#6b7280 だと 4.39:1 で基準の 4.5:1 に届かない）
        $this->assertStringContainsString('style="background: #f3f4f6; color: #4b5563;">停止</span>', $rows[0]['html']);
        $this->assertStringContainsString('style="background: #d1fae5; color: #065f46;">利用中</span>', $rows[1]['html']);
    }

    /** 編集の送信先は Alpine が組むので、式と PUT を固定する（OrganizationManagementTest と同じ見方） */
    public function test_the_edit_form_points_at_the_update_route(): void
    {
        $this->approvalWorld();
        $html = $this->indexHtml($this->approvalAdmin());

        $base   = Str::beforeLast(route('approvals.admin.types.update', 1), '/1');
        $needle = ':action="\'' . $base . '/\' + editId"';

        $form = $this->parseForm($html, $needle);
        $this->assertSame('PUT', $form['method'], '編集のフォームが PUT で送られない（405 で無反応になる）');
        $this->assertArrayHasKey('_token', $form['fields'], '編集のフォームに @csrf が無い');
    }

    /** 編集の小窓は今の値で開く（受け渡しの値・x-model・JS の代入を対で固定。Alpine が動くかはブラウザで見る） */
    public function test_the_edit_modal_is_filled_from_the_current_values(): void
    {
        $w = $this->approvalWorld();
        $w['type']->update(['is_active' => false, 'sort_order' => 3, 'headings' => "■ 目的\r\n・"]);
        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertSame([
            'id' => $w['type']->id, 'name' => $w['type']->name, 'headings' => "■ 目的\r\n・",
            'review_department_id' => $w['reviewDept']->id, 'sort_order' => 3, 'is_active' => false,
        ], $this->editRows($html)[$w['type']->id]);

        foreach ([
            '<input type="text" name="name" x-model="editName"',
            '<select name="review_department_id" x-model="editDepartmentId"',
            '<textarea name="headings" x-model="editHeadings"',
            '<input type="number" name="sort_order" x-model="editSort"',
            '<input type="checkbox" name="is_active" value="1" x-model="editActive">',
            'this.editId = row.id;',
            'this.editName = row.name;',
            'this.editDepartmentId = String(row.review_department_id);',
            'this.editHeadings = row.headings;',
            'this.editSort = String(row.sort_order);',
            'this.editActive = row.is_active;',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // 今の審査部門は必ず選択肢にある（無いと先頭の部門が選ばれたまま保存される）
        $this->assertSame(2, substr_count($html, '<option value="' . $w['reviewDept']->id . '">' . e($w['company']->name) . '・総務部</option>'));
    }

    /** 更新は送った項目をすべて保存し、変わった項目だけを前後つきで記録する（見出しは CRLF・全角の空白でも「見出しの形」なら通る） */
    public function test_every_field_is_saved_and_logged_by_the_update(): void
    {
        $w        = $this->approvalWorld();
        $headings = "■ 目的\r\n　・　\r\n\r\n■ 内容\r\n・";

        $this->actingAs($this->approvalAdmin())->put(route('approvals.admin.types.update', $w['type']), [
            'name' => '購入・発注（改）', 'headings' => $headings,
            'review_department_id' => (string) $w['dept']->id, 'sort_order' => '5', 'is_active' => '1',
        ])->assertRedirect(route('approvals.admin.types.index'));

        $type = $w['type']->fresh();
        $this->assertSame(['購入・発注（改）', $headings, $w['dept']->id, 5, true], [$type->name, $type->headings, $type->review_department_id, $type->sort_order, $type->is_active]);

        $log = ApprovalSettingLog::where('action', 'type.updated')->sole();
        $this->assertSame(['name', 'headings', 'review_department_id', 'sort_order'], array_keys($log->new_values));
        $this->assertEquals(['name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 1], $log->old_values);
        $this->assertEquals(['name' => '購入・発注（改）', 'headings' => $headings, 'review_department_id' => $w['dept']->id, 'sort_order' => 5], $log->new_values);
    }

    /** 描いた削除のフォームをそのまま送り返す。成功も失敗も、帯はレイアウトの 1 回だけ（Bug #49: セッションに触らずに描く） */
    public function test_a_type_is_deleted_from_the_rendered_form_and_the_banner_shows_once(): void
    {
        $w      = $this->approvalWorld();
        $this->draftFor($w);
        $unused = $this->approvalType($w['reviewDept'], ['name' => '人事']);
        $admin  = $this->approvalAdmin();

        foreach ([
            [$unused, '<span class="text-sm text-emerald-800">', '申請の種類を削除しました。'],
            [$w['type'], '<span class="text-sm text-red-800">', 'この種類の申請が 1 件あるため削除できません。使わなくなった種類は「停止」にしてください。'],
        ] as [$type, $banner, $message]) {
            $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.types.destroy', $type) . '"');
            $this->assertSame('DELETE', $form['method'], '削除のフォームが DELETE で送られない（405 で無反応になる）');
            $this->assertArrayHasKey('_token', $form['fields'], '削除のフォームに @csrf が無い');

            $this->actingAs($admin)->post($form['action'], $form['fields'])->assertRedirect(route('approvals.admin.types.index'));

            $html = $this->indexHtml($admin);
            $this->assertSame(1, substr_count($html, $banner . e($message) . '</span>'), "帯に出ていない: {$message}");
            $this->assertSame(1, substr_count($html, e($message)), "画面に 2 回出ている: {$message}");
        }

        $this->assertNull($unused->fresh());
        $this->assertNotNull($w['type']->fresh());
    }

    /** 押せない理由は、ボタン自身ではなくホバーを受けられる span に載せる（Bug #43。ボタンが持たないこととラッパーが持つことを対で） */
    public function test_the_reason_the_add_button_is_disabled_sits_on_the_wrapper(): void
    {
        $admin   = $this->approvalAdmin();
        $pattern = '/<span([^>]*)>\s*<button([^>]*)>種類を追加<\/button>/u';
        $bare    = '/(?<![\w:-])disabled(?![\w:-])/';

        // 部門が 0 件 ＝ 押せない
        $this->assertSame(1, preg_match($pattern, $this->indexHtml($admin), $m), 'span に包まれた「種類を追加」が見つからない');
        $this->assertStringContainsString('title="先に部門の管理で部門を登録してください。"', $m[1]);
        $this->assertSame(1, preg_match($bare, $m[2]), 'ボタンが disabled になっていない');
        $this->assertStringNotContainsString('title=', $m[2], 'disabled なボタン自身の title は表示されない（Bug #43）');

        // 部門があれば押せる ＝ 理由も出さない
        $this->approvalDepartment($this->approvalCompany());
        $this->assertSame(1, preg_match($pattern, $this->indexHtml($admin), $m));
        $this->assertStringNotContainsString('title=', $m[1], '押せるのに理由が残っている');
        $this->assertSame(0, preg_match($bare, $m[2]), '部門があるのに押せない');
    }

    /** 見出しの欄（12 行）があっても「保存する」に届くこと（構造だけ。OrganizationManagementTest と同じ見方） */
    public function test_every_modal_panel_can_scroll_on_a_short_screen(): void
    {
        $this->approvalWorld();
        $found = preg_match_all('/<div @click\.outside="[^"]*" class="([^"]*)"/', $this->indexHtml($this->approvalAdmin()), $m);

        $this->assertSame(2, $found, 'モーダルの panel が 2 つ見つからない');
        foreach ($m[1] as $class) {
            $this->assertStringContainsString('max-h-[90vh]', $class);
            $this->assertStringContainsString('overflow-y-auto', $class);
        }
    }

    /** 断られた理由が画面に出る（Bug #49: 描く前にセッションへ触らない） */
    public function test_a_rejected_form_shows_why_on_the_page(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->actingAs($admin)->from(route('approvals.admin.types.index'))->put(route('approvals.admin.types.update', $w['type']), [
            'name' => $w['type']->name, 'headings' => "■ なぜ\n※ 見積書を添付",
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1', 'is_active' => '1',
        ])->assertRedirect(route('approvals.admin.types.index'));

        $this->assertStringContainsString('<li>' . e('見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。') . '</li>', $this->indexHtml($admin));
    }

    /** 追加のフォームの審査部門は全部門から選べ、先頭以外を選んで送り返せる（ParsesForms の「先頭 option 以外で測る」） */
    public function test_the_create_form_offers_every_department(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $html  = $this->indexHtml($admin);

        $open  = strpos($html, 'action="' . route('approvals.admin.types.store') . '"');
        $form  = substr($html, $open, strpos($html, '</form>', $open) - $open);
        preg_match_all('/<option value="(\d+)">([^<]*)<\/option>/', $form, $m);
        $this->assertSame([(string) $w['dept']->id, (string) $w['reviewDept']->id], $m[1]);
        $this->assertSame([e($w['company']->name) . '・住宅事業部', e($w['company']->name) . '・総務部'], $m[2]);

        $fields = $this->parseForm($html, 'action="' . route('approvals.admin.types.store') . '"')['fields'];
        $this->actingAs($admin)->post(route('approvals.admin.types.store'), array_merge($fields, [
            'name' => '人事', 'review_department_id' => $m[1][1],
        ]))->assertRedirect(route('approvals.admin.types.index'));

        $this->assertSame($w['reviewDept']->id, ApprovalType::where('name', '人事')->sole()->review_department_id);
    }

    /** 上限と存在の検査は日本語で断る（本番の MySQL では、検査が抜けると列の長さ・UNSIGNED・外部キーで 500 になる） */
    public function test_the_limits_are_refused_in_japanese(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $base  = [
            'name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1', 'is_active' => '1',
        ];

        foreach ([
            ['headings', '', '見出しを入力してください。'],
            ['headings', '■' . str_repeat('あ', 2000), '見出しは2000文字以内で入力してください。'],
            ['name', str_repeat('あ', 51), '種類名は50文字以内で入力してください。'],
            ['review_department_id', '999999', '選択された審査部門は存在しません。'],
            ['sort_order', '-1', '表示順は0以上の値にしてください。'],
            ['sort_order', '10000', '表示順は9999以下の値にしてください。'],
        ] as [$field, $value, $message]) {
            $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), array_merge($base, [$field => $value]))
                ->assertSessionHasErrors([$field => $message]);
        }

        // 断られた送信では何も変わらず、記録も残らない
        $this->assertSame([$w['type']->name, BodyTemplate::DEFAULT, 1], [$w['type']->fresh()->name, $w['type']->fresh()->headings, $w['type']->fresh()->sort_order]);
        $this->assertSame(0, ApprovalSettingLog::count());

        // 2000 文字ちょうどは通る
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), array_merge($base, ['headings' => '■' . str_repeat('あ', 1999)]))
            ->assertSessionHasNoErrors();
        $this->assertSame('■' . str_repeat('あ', 1999), $w['type']->fresh()->headings);
    }

    /** 追加と削除の記録は値を持つ（あとで「何を消したか」を読めるように） */
    public function test_the_logs_keep_the_created_and_deleted_values(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->actingAs($admin)->post(route('approvals.admin.types.store'), [
            'name' => '人事', 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '4', 'is_active' => '1',
        ])->assertRedirect();
        $type = ApprovalType::where('name', '人事')->sole();

        $expected = ['name' => '人事', 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 4, 'is_active' => true];
        $this->assertEquals($expected, ApprovalSettingLog::where('action', 'type.created')->sole()->new_values);

        $this->actingAs($admin)->delete(route('approvals.admin.types.destroy', $type))->assertRedirect();
        $this->assertEquals($expected, ApprovalSettingLog::where('action', 'type.deleted')->sole()->old_values);
    }

    /** 削除を止めるのは、その種類の申請だけ（ほかの種類の申請では止めない） */
    public function test_only_the_requests_of_the_type_block_its_deletion(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);
        $unused = $this->approvalType($w['reviewDept'], ['name' => '人事']);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.types.destroy', $unused))
            ->assertSessionHas('success', '申請の種類を削除しました。');

        $this->assertNull($unused->fresh());
    }

    /** 追加の小窓の審査部門は「選んでください」から始まり、選ばずに送ると断る（先頭の部門が黙って選ばれない。Task 11 の点検の軽微） */
    public function test_the_create_form_starts_without_a_review_department(): void
    {
        $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->assertMatchesRegularExpression('/<select name="review_department_id" required[^>]*>\s*<option value="">選んでください<\/option>/u', $this->indexHtml($admin));

        $this->actingAs($admin)->post(route('approvals.admin.types.store'), [
            'name' => '選び忘れ', 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => '', 'sort_order' => '1',
        ])->assertSessionHasErrors(['review_department_id' => '審査部門を選択してください。']);
        $this->assertSame(0, ApprovalType::where('name', '選び忘れ')->count());
    }
}
