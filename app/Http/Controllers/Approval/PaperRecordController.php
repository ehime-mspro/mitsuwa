<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\ApprovalFiscalYear;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\FormInput;
use App\Support\Approval\PageNumbers;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\SettingLogger;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 取り込んだ紙の決裁（画面⑪の一覧と 1 件ずつの登録・直し・削除。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から使える（D11）。
 *
 * 一覧（年度・部門で絞る）と取り込みの記録（新しい順・取り消し）を出す。Excel の台帳の取り込みと取り消しは PaperImportController。
 *
 * - 決裁No は年度・部門・ＮＯ から組み立てる（`ApprovalNumber::paperNumber()`）。紙の番号どうし・システムの申請の番号と重なれば断る
 * - 今年度の番号を足す・直すと、その部門の次の番号を進める（D7。戻さない）。連番の行をロックしてからシステムの申請の番号をロック付きの
 *   読みで確かめる（社長の判断の採番と重ならない。取り込みと同じ部品 ApprovalNumber::reservePaperNumbers）
 * - 決裁日は、選んだ年度の期間（部門の会社の期）の中で、今日より前の日だけ（今日より先・まだ始まっていない会社の年度の番号を入れると、
 *   次の番号を進められずに、あとでシステムの採番と重なる）
 * - 設定の記録に残す（paper.created・paper.updated〈変わった項目だけ〉・paper.deleted）
 *
 * ⚠ 絞り込みは GET。選択肢に無い値は外す（手で打った URL。断って前の画面へ戻すと同じ URL を開き直し続ける＝台帳の LedgerFilter と同じ考え）
 * ⚠ `updated_by` は手で直した人（取り込みの取り消しの「あとで手で直した記録」の数に使う）。登録では入れない
 */
class PaperRecordController extends Controller
{
    /** 一覧の 1 ページの件数 */
    public const PER_PAGE = 50;

    /** 備考の上限 */
    public const NOTE_MAX = 2000;

    /** 設定の記録に残す項目（フォームの欄と、組み立てた決裁No） */
    private const FIELDS = ['number', 'fiscal_year', 'department_id', 'seq', 'decided_on', 'applicant_name', 'subject', 'amount', 'cost', 'profit', 'note'];

    /** Route: GET /approvals/admin/papers */
    public function index(Request $request): View
    {
        $departments = $this->departments();
        $years       = ApprovalPaperRecord::yearChoices();

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
            // 取り消しの確かめに、今その回の記録が何件あり、そのうち何件を手で直したかを出す（D9）
            // 取り込みの記録はすべて出す（古い回も取り消せるように。年度ごとのファイルなので多くならない）
            'imports'     => ApprovalPaperImport::with(['importer', 'undoer'])
                ->withCount(['records', 'records as edited_count' => fn ($q) => $q->whereNotNull('updated_by')])
                ->latest('id')->get(),
        ]);
    }

    /** Route: GET /approvals/admin/papers/create */
    public function create(): View
    {
        return view('approvals.admin.papers.form', ['record' => null] + $this->formChoices());
    }

    /** Route: POST /approvals/admin/papers */
    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request);

        $record = $this->save($values, function (array $values) use ($request): ApprovalPaperRecord {
            $record = ApprovalPaperRecord::create($values + ['created_by' => $request->user()->id]);
            SettingLogger::record('paper.created', 'approval_paper_record', $record->id, [], $this->logValues($values));

            return $record;
        }, null);

        return $this->saved($record, "{$record->number} を登録しました。");
    }

    /** Route: GET /approvals/admin/papers/{approvalPaperRecord}/edit */
    public function edit(ApprovalPaperRecord $approvalPaperRecord): View
    {
        return view('approvals.admin.papers.form', ['record' => $approvalPaperRecord->load(['department', 'import', 'creator', 'editor'])] + $this->formChoices());
    }

    /** Route: PUT /approvals/admin/papers/{approvalPaperRecord} */
    public function update(Request $request, ApprovalPaperRecord $approvalPaperRecord): RedirectResponse
    {
        $values = $this->validated($request);
        $before = $this->storedValues($approvalPaperRecord);

        $record = $this->save($values, function (array $values) use ($request, $approvalPaperRecord, $before): ApprovalPaperRecord {
            $approvalPaperRecord->update($values + ['updated_by' => $request->user()->id]);
            SettingLogger::recordChange('paper.updated', 'approval_paper_record', $approvalPaperRecord->id, $before, $this->logValues($values));

            return $approvalPaperRecord;
        }, $approvalPaperRecord);

        return $this->saved($record, "{$record->number} を直しました。");
    }

    /** Route: DELETE /approvals/admin/papers/{approvalPaperRecord}。次の番号は戻さない（D9） */
    public function destroy(ApprovalPaperRecord $approvalPaperRecord): RedirectResponse
    {
        $before = $this->storedValues($approvalPaperRecord);
        $id     = $approvalPaperRecord->id;
        $number = $approvalPaperRecord->number;
        $year   = $approvalPaperRecord->fiscal_year;

        DB::transaction(function () use ($approvalPaperRecord, $id, $before): void {
            $approvalPaperRecord->delete();
            SettingLogger::record('paper.deleted', 'approval_paper_record', $id, $before, []);
        });

        return redirect()->route('approvals.admin.papers.index', ['year' => $year])->with('success', "{$number} を削除しました。");
    }

    /**
     * 欄の検査（金額は全角・カンマ・円を落としてから。マイナスも入れられる＝値引きの行のある台帳）。決裁No も組み立てて返す
     *
     * ⚠ ルールは literal の配列で書く（JapaneseValidationMessagesTest の走査が和名の漏れを見る）
     *
     * @return array<string, mixed> 保存する列
     */
    private function validated(Request $request): array
    {
        $request->merge([
            'seq'            => FormInput::digits($request->input('seq')),
            'amount'         => FormInput::digits($request->input('amount'), FormInput::YEN),
            'cost'           => FormInput::digits($request->input('cost'), FormInput::YEN),
            'profit'         => FormInput::digits($request->input('profit'), FormInput::YEN),
            'applicant_name' => is_string($request->input('applicant_name')) ? FormInput::trim($request->input('applicant_name')) : $request->input('applicant_name'),
            'subject'        => is_string($request->input('subject')) ? FormInput::trim($request->input('subject')) : $request->input('subject'),
        ]);
        FormInput::unifyNewlines($request, 'note');

        $validated = $request->validate([
            'fiscal_year'    => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
            'department_id'  => ['required', 'integer', Rule::in($this->departments()->pluck('id')->all())],
            'seq'            => ['bail', 'required', 'integer', 'min:1', 'max:' . PaperImportAnalysis::SEQ_MAX],
            'decided_on'     => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1989-01-01', 'before_or_equal:' . JapanTime::today()->format('Y-m-d')],
            'applicant_name' => ['nullable', 'string', 'max:' . PaperImportAnalysis::APPLICANT_MAX],
            'subject'        => ['required', 'string', 'max:' . PaperImportAnalysis::SUBJECT_MAX],
            'amount'         => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
            'cost'           => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
            'profit'         => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
            'note'           => ['nullable', 'string', 'max:' . self::NOTE_MAX],
        ], [
            'department_id.required'    => '部門を選んでください。',
            'seq.required'              => 'ＮＯ を入力してください。',
            'decided_on.required'       => '決裁日を入力してください。',
            'subject.required'          => '件名を入力してください。',
            'fiscal_year.in'            => '年度を選び直してください。',
            'department_id.in'          => '部門を選び直してください。',
            'seq.integer'               => 'ＮＯ は数で入力してください。',
            'seq.min'                   => 'ＮＯ は 1 から 99,999 までで入力してください。',
            'seq.max'                   => 'ＮＯ は 1 から 99,999 までで入力してください。',
            'decided_on.date_format'    => '決裁日は正しい日付で入力してください。',
            'decided_on.after_or_equal' => '決裁日は 1989 年からの日付で入力してください。',
            'decided_on.before_or_equal' => '決裁日に今日より先の日付は入れられません。',
            'applicant_name.max'        => '申請者は ' . PaperImportAnalysis::APPLICANT_MAX . ' 文字以内で入力してください。',
            'subject.max'               => '件名は ' . PaperImportAnalysis::SUBJECT_MAX . ' 文字以内で入力してください。',
            'amount.integer'            => '金額「:input」は円の数で入力してください（マイナスも入れられます）。',
            'amount.min'                => '金額は 12 桁までで入力してください。',
            'amount.max'                => '金額は 12 桁までで入力してください。',
            'cost.integer'              => '工事原価「:input」は円の数で入力してください（マイナスも入れられます）。',
            'cost.min'                  => '工事原価は 12 桁までで入力してください。',
            'cost.max'                  => '工事原価は 12 桁までで入力してください。',
            'profit.integer'            => '粗利益「:input」は円の数で入力してください（マイナスも入れられます）。',
            'profit.min'                => '粗利益は 12 桁までで入力してください。',
            'profit.max'                => '粗利益は 12 桁までで入力してください。',
            'note.max'                  => '備考は ' . number_format(self::NOTE_MAX) . ' 文字以内で入力してください。',
        ], [
            'fiscal_year'    => '年度',
            'department_id'  => '部門',
            'seq'            => 'ＮＯ',
            'decided_on'     => '決裁日',
            'applicant_name' => '申請者',
            'subject'        => '件名',
            'amount'         => '金額',
            'cost'           => '工事原価',
            'profit'         => '粗利益',
            'note'           => '備考',
        ]);

        $department = ApprovalDepartment::with('company')->findOrFail((int) $validated['department_id']);

        // 決裁日は選んだ年度の期間の中だけ（部門の会社の期）
        $month = $department->company->fiscal_start_month;
        $day   = CarbonImmutable::createFromFormat('!Y-m-d', $validated['decided_on']);
        if (ApprovalFiscalYear::of($day, $month) !== (int) $validated['fiscal_year']) {
            $start = CarbonImmutable::create((int) $validated['fiscal_year'], $month, 1);
            throw ValidationException::withMessages(['decided_on' => sprintf(
                '決裁日 %s は %s 年度の期間（%s〜%s）の外です。年度か決裁日を直してください。',
                $day->format('Y/m/d'),
                ApprovalFiscalYear::eraLabel((int) $validated['fiscal_year'], $month),
                $start->format('Y/m/d'),
                $start->addYear()->subDay()->format('Y/m/d')
            )]);
        }

        $values     = [
            'fiscal_year'    => (int) $validated['fiscal_year'],
            'department_id'  => $department->id,
            'seq'            => (int) $validated['seq'],
            'decided_on'     => $validated['decided_on'],
            'applicant_name' => ($validated['applicant_name'] ?? '') === '' ? null : $validated['applicant_name'],
            'subject'        => $validated['subject'],
            'amount'         => isset($validated['amount']) ? (int) $validated['amount'] : null,
            'cost'           => isset($validated['cost']) ? (int) $validated['cost'] : null,
            'profit'         => isset($validated['profit']) ? (int) $validated['profit'] : null,
            'note'           => ($validated['note'] ?? '') === '' ? null : $validated['note'],
        ];

        return $values + ['number' => ApprovalNumber::paperNumber($department, $values['fiscal_year'], $values['seq'])];
    }

    /**
     * 番号を確かめて保存する（1 つのトランザクション）。今年度の番号なら次の番号を進め、ロックのあとでシステムの申請の番号を確かめる
     *
     * @param callable(array): ApprovalPaperRecord $write
     * @throws ValidationException 番号が重なる（入れた中身を残してフォームへ戻す）
     */
    private function save(array $values, callable $write, ?ApprovalPaperRecord $current): ApprovalPaperRecord
    {
        $refuse = fn (string $message) => ValidationException::withMessages(['seq' => $message]);

        try {
            return DB::transaction(function () use ($values, $write, $current, $refuse): ApprovalPaperRecord {
                // 次の番号を進め、ロックのあとでシステムの申請の番号をロック付きの読みで確かめる（取り込みと同じ部品）
                $reserved = ApprovalNumber::reservePaperNumbers([$values['department_id'] => $values['seq']], $values['fiscal_year'], [$values['number']]);
                if ($reserved['taken'] !== []) {
                    throw $refuse("{$values['number']} はシステムの申請がすでに使っています。ＮＯ を確かめてください。");
                }
                if (ApprovalPaperRecord::where('number', $values['number'])->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
                    throw $refuse("{$values['number']} の紙の決裁はすでにあります。ＮＯ を確かめてください。");
                }

                return $write($values);
            });
        } catch (UniqueConstraintViolationException) {
            // 確かめたあとで、ほかの管理者が同じ番号を登録した
            throw $refuse("{$values['number']} の紙の決裁はすでにあります。ＮＯ を確かめてください。");
        }
    }

    /** 保存したあと（その年度の一覧へ） */
    private function saved(ApprovalPaperRecord $record, string $message): RedirectResponse
    {
        return redirect()->route('approvals.admin.papers.index', ['year' => $record->fiscal_year])->with('success', $message);
    }

    /** 今の記録の値（設定の記録の「前」。決裁日は保存する形の文字にそろえる＝変えていない日付を「変わった」と記録しない） */
    private function storedValues(ApprovalPaperRecord $record): array
    {
        return array_merge($record->only(self::FIELDS), ['decided_on' => $record->decided_on->format('Y-m-d')]);
    }

    /** 設定の記録の値（決裁日は文字のまま） */
    private function logValues(array $values): array
    {
        return array_intersect_key($values, array_flip(self::FIELDS));
    }

    /** フォームの選択肢 */
    private function formChoices(): array
    {
        return ['years' => ApprovalPaperRecord::yearChoices(), 'departments' => $this->departments()];
    }

    /** 部門（会社の並び・部門の並び） @return Collection<int, ApprovalDepartment> */
    private function departments(): Collection
    {
        return ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();
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
