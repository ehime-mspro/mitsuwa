<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\PageNumbers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * 取り込んだ紙の決裁（画面⑪の一覧。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から使える（D11）。
 *
 * 一覧（年度・部門で絞る）と、取り込みの記録（新しい順）を出す。Excel の台帳の取り込みは PaperImportController。
 *
 * ⚠ 絞り込みは GET。選択肢に無い値は外す（手で打った URL。断って前の画面へ戻すと同じ URL を開き直し続ける＝台帳の LedgerFilter と同じ考え）
 */
class PaperRecordController extends Controller
{
    /** 一覧の 1 ページの件数 */
    public const PER_PAGE = 50;

    /** 取り込みの記録を出す数（新しい順） */
    private const IMPORTS_SHOWN = 20;

    /** Route: GET /approvals/admin/papers */
    public function index(Request $request): View
    {
        $departments = ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();
        $years = ApprovalPaperRecord::yearChoices();

        // 選択肢に無い値は外す（数の形でない・無い年度・無い部門）
        $year       = $this->choice($request->query('year'), array_keys($years));
        $department = $this->choice($request->query('department'), $departments->pluck('id')->all());

        $query = ApprovalPaperRecord::with('department')
            ->when($year !== null, fn ($q) => $q->where('fiscal_year', $year))
            ->when($department !== null, fn ($q) => $q->where('department_id', $department))
            ->orderByDesc('decided_on')
            ->orderBy('number');

        // ページ番号は最後のページまで（Bug #100。手で打った大きな番号で PageNumbers::around() が TypeError の 500・行の無いページは件数と食い違う）
        $total   = $query->count();
        $page    = min(LengthAwarePaginator::resolveCurrentPage(), max(1, (int) ceil($total / self::PER_PAGE)));
        $records = $query->paginate(self::PER_PAGE, ['*'], 'page', $page, $total)->withQueryString();

        // 年度の選択肢は紙の決裁のある年度だけ（平成元年度からの全部を並べると長い）。選んでいる年度は残す
        $recordYears = ApprovalPaperRecord::query()->distinct()->pluck('fiscal_year')->map(fn (mixed $y) => (int) $y)->push($year)->filter()->all();

        return view('approvals.admin.papers.index', [
            'records'     => $records,
            'pages'       => PageNumbers::around($records->currentPage(), $records->lastPage()),
            'years'       => $years,
            'yearChoices' => array_intersect_key($years, array_flip($recordYears)),
            'departments' => $departments,
            'year'        => $year,
            'department'  => $department,
            'imports'     => ApprovalPaperImport::with(['importer', 'undoer'])->latest('id')->limit(self::IMPORTS_SHOWN)->get(),
        ]);
    }

    /**
     * 絞り込みの値（正の整数の形で、選択肢にあるものだけ）
     *
     * @param list<int> $choices
     */
    private function choice(mixed $raw, array $choices): ?int
    {
        if (! is_string($raw) || ! preg_match('/\A[1-9]\d{0,9}\z/', $raw)) {
            return null;
        }

        return in_array((int) $raw, $choices, true) ? (int) $raw : null;
    }
}
