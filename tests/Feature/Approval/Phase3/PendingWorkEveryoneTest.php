<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 全員分の対応待ち（毎朝の催促。段階3 設計書 §5.8・§6 の「全員分の部品と PendingWork::for() の突き合わせ」）。
 *
 * ⚠ 規則をここだけ変えると落ちるように、いろいろな段階の申請と全員の組み合わせで for() と比べる（StepHandlersTest と同じ考え）。
 */
class PendingWorkEveryoneTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function workflow(): Workflow
    {
        return app(Workflow::class);
    }

    /** 判断して読み直す（判断を省くと部門長・社長は承認〈可〉、審査は可） */
    private function judge(string $kind, ApprovalRequest $request, User $actor, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        $this->workflow()->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /** @return list<array{int, string, string, ?string}> 申請の id・役割・対応・番が来た日時（比べやすい形） */
    private function keys(Collection $items): array
    {
        return $items->map(fn (array $item) => [$item['request']->id, $item['role'], $item['action'], $item['since']?->format('Y-m-d H:i:s')])->all();
    }

    /**
     * 部門長確認中（そのまま・付け替え・部門長が申請者で省いた審査）・審査中（審査担当者に申請者本人・無効・削除を混ぜる）・
     * 社長決裁待ち（あとで申請者を社長に指定したものを含む）・差戻し中・条件確認待ち・決裁済みの申請と、全員の組み合わせ
     */
    public function test_everyone_gives_each_person_exactly_what_for_gives(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $other    = $this->baseUser(['name' => '付け替え 先']);
        $second   = $this->baseUser(['name' => '審査 二人目']);
        $inactive = $this->baseUser(['name' => '無効 審査']);
        $deleted  = $this->baseUser(['name' => '削除 審査']);
        $reviewerApplicant = $this->approvalOnlyUser(['name' => '審査 兼 申請']);
        $reviewerApplicant->approvalDepartments()->attach($w['dept']->id);
        $w['reviewDept']->reviewers()->attach([$second->id, $inactive->id, $deleted->id, $reviewerApplicant->id]);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();
        // 部門長が申請者の申請（部門長の段階を省いて審査へ）と、2 人目の申請者
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $applicant2 = $this->approvalOnlyUser(['name' => '申請 二郎']);
        $applicant2->approvalDepartments()->attach($w['dept']->id);

        // 番が来た順と段階の id の順を食い違わせる（審査 兼 申請の人は、先に自分の申請が差し戻され〈1 日目〉、あとから審査の番が
        // 来る〈2 日目〉。全員分は段階 → 申請者の番の順に集めるので、番が来た順に並べ替えないと逆の順になる）
        // ⚠ 時計は UTC にしてから渡す（日本時間のまま渡すと、保存した日時を日本時間として読む。計画 §0.12）
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Tokyo')->utc());
        $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head'], ApprovalStepResult::Return);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Tokyo')->utc());

        $this->submittedFor($w);
        $reassigned = $this->submittedFor($w);
        $this->workflow()->reassignHead($reassigned, $admin, $reassigned->lock_version, $other, '出張のため');
        $this->judge('head', $this->submittedFor($w), $w['head']);
        $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head']);
        $this->submittedFor(array_merge($w, ['applicant' => $w['head']]));
        $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
        $this->judge('review', $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $applicant2])), $w['head']), $w['reviewer']);
        $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president'], ApprovalStepResult::Conditional);
        $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president']);
        // 社長決裁待ちの申請の申請者を社長に指定する（自分の申請は社長の対応待ちに出ない。D16）
        $this->makePresident($w['applicant']);

        $everyone = PendingWork::everyone();
        $people   = User::withTrashed()->get();
        $this->assertCount(11, $people);

        $compared = 0;
        $items    = 0;
        foreach ($people as $user) {
            // 削除した人は for() を呼ばない（画面に出ない）。全員分では審査担当者として残るが、催促の宛先で絞る
            if ($user->trashed()) {
                continue;
            }

            $expected = $this->keys(PendingWork::for($user));
            $this->assertSame($expected, $this->keys($everyone->get($user->id, collect())), "{$user->name} の対応待ちが for() と違う");
            $compared++;
            $items += count($expected);
        }

        // 空振りで緑にならないように（削除した 1 人を除く 10 人を比べ、対応待ちは合わせて 17 件）
        $this->assertSame(10, $compared);
        $this->assertSame(17, $items);
        // 並べ替えを見る場面になっていること（審査 兼 申請の人は、審査の番より先に自分の差戻しの番が来ている）
        $this->assertSame(['申請者', '審査', '審査'], $everyone->get($reviewerApplicant->id)->pluck('role')->all());
        // 対応待ちの無い人は入らない
        $this->assertFalse($everyone->has($admin->id));
    }

    /** 問い合わせの数は人数・申請の数によらない（1 人ずつ for() を呼ばない） */
    public function test_the_number_of_queries_does_not_grow_with_people_or_requests(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            PendingWork::everyone();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 3) as $n) {
            $w['reviewDept']->reviewers()->attach($this->baseUser(['name' => "審査 {$n}"])->id);
            $this->judge('head', $this->submittedFor($w), $w['head']);
            $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
            $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        }

        $this->assertSame($few, $count());
        $this->assertGreaterThan(5, PendingWork::everyone()->flatten(1)->count());
    }
}
