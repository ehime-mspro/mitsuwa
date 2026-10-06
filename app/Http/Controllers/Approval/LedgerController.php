<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalType;
use App\Support\Approval\DownloadLogger;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerExcel;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\PageNumbers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）と Excel の出力（§5.9・§5.10・D23〜D25）。
 * 見られる人なら誰でも開ける（見られる申請だけが出る）。
 *
 * ⚠ 絞り込みは GET。読み取れない条件は断らずに外して知らせる（LedgerFilter。validate() で戻すと同じ URL を開き直し続ける）。
 * ⚠ 並びは id の並びとして作り（Ledger::sortedIds）、今のページの分だけ行を読む（N+1 にしない）。
 */
class LedgerController extends Controller
{
    /** Route: GET /approvals/ledger */
    public function index(Request $request): View
    {
        $viewer  = $request->user();
        $choices = $this->choices();
        $filter  = $this->filter($request, $choices);
        $ids     = Ledger::sortedIds($viewer, $filter);
        $page    = LengthAwarePaginator::resolveCurrentPage();

        $rows = new LengthAwarePaginator(
            Ledger::rows($viewer, array_slice($ids, ($page - 1) * Ledger::PER_PAGE, Ledger::PER_PAGE)),
            count($ids),
            Ledger::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $filter->query()],
        );

        return view('approvals.ledger.index', $choices + [
            'filter'     => $filter,
            'rows'       => $rows,
            'pages'      => PageNumbers::around($rows->currentPage(), $rows->lastPage()),
            'excelLimit' => (int) config('approval.ledger.excel_limit'),
        ]);
    }

    /** Route: GET /approvals/ledger/excel（台帳と同じ条件・同じ並びで全件。件数の上限を超えたら作らない） */
    public function excel(Request $request): Response|RedirectResponse
    {
        $viewer = $request->user();
        $filter = $this->filter($request, $this->choices());
        $ids    = Ledger::sortedIds($viewer, $filter);
        $limit  = (int) config('approval.ledger.excel_limit');

        if (count($ids) > $limit) {
            return redirect()->route('approvals.ledger.index', $filter->query())
                ->with('error', '絞り込んでから出してください（Excel に出せるのは ' . number_format($limit) . ' 件までです。いまは ' . number_format(count($ids)) . ' 件）。');
        }

        // ⚠ 作るのに失敗したときの知らせは PDF のようには作らない（PDF はフォントなど外の物に頼るが、Excel の失敗はメモリの
        //   不足くらいで、それは捕まえられない。件数の上限で防ぐ）。例外は Laravel がほかの画面と同じく laravel.log に残す
        $excel = LedgerExcel::build($viewer, $ids);

        // 出力のたびに、絞り込みの条件と件数を記録する（14.2・D25）。作ったあとに書く（作れなかったら記録しない。§5.7）
        DownloadLogger::excel($request, $filter->toLog(), count($ids));

        // 日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前（Laravel の Str::ascii に任せない。Bug #68）
        [$fileName, $fallback] = LedgerExcel::fileNames();

        return response($excel, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fallback),
        ]);
    }

    /**
     * 絞り込みの選択肢（年度・申請部門・申請の種類）
     *
     * @return array{years: array<int, string>, departments: Collection<int, ApprovalDepartment>, types: Collection<int, ApprovalType>}
     */
    private function choices(): array
    {
        return [
            'years'       => Ledger::years(),
            'departments' => ApprovalDepartment::with('company')->get()
                ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
                ->values(),
            'types'       => ApprovalType::query()->ordered()->get(),
        ];
    }

    /**
     * 条件（選択肢に無い年度・部門・種類は外して知らせる）
     *
     * @param array{years: array<int, string>, departments: Collection<int, ApprovalDepartment>, types: Collection<int, ApprovalType>} $choices
     */
    private function filter(Request $request, array $choices): LedgerFilter
    {
        return LedgerFilter::fromRequest($request)->within(
            array_keys($choices['years']),
            $choices['departments']->pluck('id')->all(),
            $choices['types']->pluck('id')->all(),
        );
    }
}
