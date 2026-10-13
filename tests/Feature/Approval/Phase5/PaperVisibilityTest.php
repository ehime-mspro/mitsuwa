<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperRecord;
use App\Models\User;
use App\Support\Approval\RequestVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁の見られる範囲（要件 7 章の最後・段階5 設計書 §5.12）。
 *
 * その部門に所属する人・その部門の今の部門長・全件閲覧者・決裁の管理者だけ。紙の時代の申請者は利用者と結び付けないので、
 * 同じ名前の人でも「自分の申請」としては見えない。社長は全件閲覧者にも指定されていれば見られる。
 * 1 件の判定（canViewPaper）と一覧の絞り込み（visibleTo）は同じ規則から作る（どちらも確かめる）。
 */
class PaperVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** @return list<int> 見られる紙の決裁の id（小さい順） */
    private function visibleIds(User $user): array
    {
        return ApprovalPaperRecord::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    public function test_who_can_see_a_paper_record(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company'], ['code' => 'M']);
        $ownJ   = $this->paperRecord($w['dept'], ['applicant_name' => '申請 花子']);
        $ownM   = $this->paperRecord($other);
        $both   = [$ownJ->id, $ownM->id];

        $memberOfM = $this->approvalOnlyUser(['name' => 'ミツワ 不動産']);
        $memberOfM->approvalDepartments()->attach($other->id);
        $twoDepts  = $this->approvalOnlyUser(['name' => '兼務 の人']);
        $twoDepts->approvalDepartments()->attach([$w['dept']->id, $other->id]);
        $headOfM   = $this->baseUser(['name' => 'M の 部門長']);
        $other->update(['head_user_id' => $headOfM->id]);
        $stranger  = $this->baseUser(['name' => '関係 ない人']);
        $viewAll   = $this->viewAllUser();
        $admin     = $this->approvalAdmin();

        $cases = [
            '申請部門に所属する人（同じ名前でも自分の申請としてではなく自部門の過去分として）' => [$w['applicant'], [$ownJ->id]],
            'ほかの部門に所属する人'              => [$memberOfM, [$ownM->id]],
            '2 つの部門に所属する人'              => [$twoDepts, $both],
            'その部門の今の部門長（所属していない）' => [$w['head'], [$ownJ->id]],
            'ほかの部門の部門長'                  => [$headOfM, [$ownM->id]],
            '審査担当者（所属していない）'          => [$w['reviewer'], []],
            '社長だけ'                            => [$w['president'], []],
            '関わりの無い人'                      => [$stranger, []],
            '全件閲覧者'                          => [$viewAll, $both],
            '決裁の管理者'                        => [$admin, $both],
        ];

        foreach ($cases as $label => [$user, $expected]) {
            $this->assertSame($expected, $this->visibleIds($user), $label);
            foreach ([$ownJ, $ownM] as $record) {
                $this->assertSame(in_array($record->id, $expected, true), RequestVisibility::canViewPaper($user, $record), "{$label}: {$record->number} の 1 件の判定が一覧と違う");
            }
        }

        // 社長を全件閲覧者にも指定すれば見られる（要件 7 章）
        $this->makePresident($viewAll);
        $this->assertSame($both, $this->visibleIds($viewAll->fresh()));

        // 部門長を外れた人は見られなくなる（今の担当で決める）
        $other->update(['head_user_id' => null]);
        $this->assertSame([], $this->visibleIds($headOfM->fresh()));

        // 保存していない利用者には何も見せない
        $this->assertSame([], $this->visibleIds(new User()));
    }

    /** 呼ぶ側が先に書いた OR から、見られない紙の決裁が漏れない（スコープが括弧に入る） */
    public function test_an_or_before_the_scope_does_not_leak(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company']);
        $mine   = $this->paperRecord($w['dept'], ['subject' => '見える契約']);
        $hidden = $this->paperRecord($other, ['subject' => '見えない契約']);

        $ids = ApprovalPaperRecord::query()
            ->where('subject', '見える契約')->orWhere('subject', '見えない契約')
            ->visibleTo($w['applicant'])
            ->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
    }
}
