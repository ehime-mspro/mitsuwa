<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImporter;
use App\Support\Approval\PaperImportException;
use App\Support\Approval\SettingLogger;
use App\Support\OneTimeAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 過去の紙の決裁の取り込みと、取り込み 1 回分の取り消し（画面⑪。要件 11 章・段階5 設計書 §5.9・§5.10・D6・D7・D9・D20）。決裁の管理者だけ・使い始める前から使える（D11）。
 *
 * 流れ（社員の CSV 一括登録と同じ形）: ファイル（.xls・.xlsx・10MB まで）と年度を選ぶ → 確認の画面（何も書かない）
 *   → 描いた「取り込む」フォーム（hidden のファイルと 1 回限りの鍵・金額の控えの結び付けの選択）で確定
 *   → **確定でファイルを読み直して確かめ直す**（hidden を信用しない）→ 紙の決裁の一覧へ
 *
 * ⚠ 断るときの戻り先は**取り込みの画面に固定する**（`back()` や検証エラーの既定の戻り先を使わない）。確認の画面は POST の応答で、
 *   そこに載ったフォームから送るとリファラーが POST 専用の preview になり、GET で 405 になる（docs/RULES.md Bug #64）。
 * ⚠ 結び付けの選び方の誤り（同じ記録に 2 つの控え・取り込めない先）は、確認の画面をもう一度出して選び直してもらう（アップロードし直させない）。
 * ⚠ 確認の画面を見ているあいだにシステムの申請が台帳と同じ番号を取ると、読み直したときその行は「取り込まない行」になって黙って落ちる。
 *   確認の画面が持たせた「足すとした決裁No」（hidden の numbers）のうち消えた番号をシステムの申請が持っていれば、何も書かずに断る
 *   （numbersTakenSince）。⚠ hidden は断るためにだけ使う（書き込みには使わない）。紙の側で消えた番号（ほかの人が先に登録した）は足さないだけ。
 * ⚠ 行のエラーの view のキーは `rowErrors`（`errors` にすると Blade の `$errors` を上書きして 500。Bug #53）。
 */
class PaperImportController extends Controller
{
    /** 確定の 2 回目（同じ確認の画面から送り直した）の断り */
    public const ALREADY_SENT = 'この確認の画面からは取り込めません（すでに送ったか、画面が古くなっています）。取り込まれたかは紙の決裁の一覧で確かめられます。取り込み直すときは、台帳をアップロードし直してください。';

    /** 確定で読み直したら、足す記録も金額を補う記録も無かった（ほかの人が先に取り込んだなど） */
    public const NOTHING_TO_IMPORT = '取り込む記録がありません（足す決裁No も、金額を補う記録もありません）。';

    /** Route: GET /approvals/admin/papers/import */
    public function form(): View
    {
        return view('approvals.admin.papers.import', ['years' => ApprovalPaperRecord::yearChoices()]);
    }

    /** Route: POST /approvals/admin/papers/import/preview */
    public function preview(Request $request): View|RedirectResponse
    {
        // ⚠ ルールの配列は行を分けて閉じる（JapaneseValidationMessagesTest の走査。UserImportController と同じ）
        try {
            $validated = $request->validate([
                'ledger_file' => ['required', 'file', 'extensions:xls,xlsx', 'max:10240'],
                'year'        => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
            ], [
                'ledger_file.required'   => '台帳のファイルを選んでください。',
                'ledger_file.extensions' => '台帳のファイル（.xls・.xlsx）を選んでください。',
                'ledger_file.max'        => '台帳のファイルは 10MB までです。',
                'year.in'                => '年度を選び直してください。',
            ], [
                'ledger_file' => '台帳のファイル',
                'year'        => '年度',
            ]);
        } catch (ValidationException $e) {
            // 確認の画面からアップロードし直したときも取り込みの画面へ戻す（Bug #64）
            throw $e->redirectTo(route('approvals.admin.papers.import'));
        }

        $file = $request->file('ledger_file');

        return $this->confirmation((string) $file->get(), (string) $file->getClientOriginalName(), (int) $validated['year'], []);
    }

    /** Route: POST /approvals/admin/papers/import/execute */
    public function execute(Request $request): View|RedirectResponse
    {
        try {
            $validated = $request->validate([
                'ledger_data' => ['required', 'string'],
                'file_name'   => ['required', 'string', 'max:255'],
                'year'        => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
                'numbers'     => ['nullable', 'string', 'max:70000'],
                'links'       => ['nullable', 'array'],
                'links.*'     => ['string', 'max:20'],
            ], [], [
                'ledger_data' => '取り込むファイル',
                'file_name'   => 'ファイルの名前',
                'year'        => '年度',
                'numbers'     => '取り込む決裁No',
                'links'       => '金額の控えの結び付け',
                'links.*'     => '金額の控えの結び付け',
            ]);
        } catch (ValidationException $e) {
            // 確定のフォームも確認の画面（POST の応答）に載っている。取り込みの画面へ戻す（Bug #64）
            throw $e->redirectTo(route('approvals.admin.papers.import'));
        }

        // 確定は確認の画面 1 つにつき 1 回だけ（二度押し・再送信）。⚠ ファイルを読み直す前に受け取る（2 回目に「取り込む記録がありません」と
        //   違う理由を出さない）。結び付けを選び直す画面をもう一度出すときは、新しい鍵を描く（confirmationView）
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('approvals.admin.papers.import')->with('error', self::ALREADY_SENT);
        }

        $content = base64_decode($validated['ledger_data'], true);
        if ($content === false) {
            return redirect()->route('approvals.admin.papers.import')->with('error', '取り込むファイルを読み取れませんでした。もう一度アップロードしてください。');
        }

        $year  = (int) $validated['year'];
        $links = $validated['links'] ?? [];

        try {
            // ⚠ 確定でもファイルを読み直して確かめ直す（画面が送ってきた hidden を信用しない）
            $analysis = $this->analyze($content, $year, $links);
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        }

        // 確認の画面で足すとした番号を、読み直したらシステムの申請が持っていて消えていた（見ているあいだに社長の判断が付けた）→ 何も書かずに断る
        $taken = $this->numbersTakenSince($validated['numbers'] ?? '', $analysis);
        if ($taken !== []) {
            return redirect()->route('approvals.admin.papers.import')->with('error', PaperImporter::systemNumbersTaken($taken));
        }

        // 結び付けの選び方の誤りは、確認の画面をもう一度出して選び直してもらう
        if ($analysis['linkErrors'] !== []) {
            return $this->confirmationView($analysis, $content, $validated['file_name'], $year, $links);
        }

        if ($analysis['addCount'] === 0 && $analysis['fillCount'] === 0) {
            return redirect()->route('approvals.admin.papers.import')->with('error', self::NOTHING_TO_IMPORT);
        }

        try {
            $result = PaperImporter::apply($analysis, $year, $validated['file_name'], $request->user());
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('approvals.admin.papers.import')->with('error', 'ほかの人が同じ番号の紙の決裁を登録しました。もう一度アップロードして確かめてください。');
        }

        // 次の番号は、確定で実際に進めた値（確認の画面のあとで採番があっても合う）
        $import = $result['import'];
        $next   = $result['next'] === [] ? '' : '次の番号: ' . implode('・', $result['next']) . '。';

        return redirect()->route('approvals.admin.papers.index', ['year' => $year])
            ->with('success', "{$import->added_count} 件を取り込みました（金額を補った記録 {$import->filled_count} 件）。{$next}");
    }

    /**
     * Route: POST /approvals/admin/papers/imports/{approvalPaperImport}/undo
     *
     * 取り込み 1 回分の取り消し（D9）: その回に足した記録を、そのあと手で直したものも含めて消す。金額を補っただけの記録は消さない
     * （その回に足したものではない）。次の番号は戻さない（部門の管理の開始番号で戻す）。取り込みの行は消さずに、取り消した日時・人・件数を残す。
     * ⚠ 一覧で見せた件数（`count_shown`）と、そのうち手で直した件数（`edited_shown`）が今と違えば断る（見せたあとで足し消し・直しがあった
     *   ＝確かめた中身と違うものを消さない）
     */
    public function undo(Request $request, ApprovalPaperImport $approvalPaperImport): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'count_shown'  => ['required', 'integer', 'min:0'],
                'edited_shown' => ['required', 'integer', 'min:0'],
            ], [], [
                'count_shown'  => '消す件数',
                'edited_shown' => '手で直した件数',
            ]);
        } catch (ValidationException $e) {
            // 一覧の画面へ戻す（取り込みのコントローラは back() を使わない。Bug #64）
            throw $e->redirectTo(route('approvals.admin.papers.index'));
        }

        $result = DB::transaction(function () use ($approvalPaperImport, $validated, $request): string|int {
            $import = ApprovalPaperImport::whereKey($approvalPaperImport->id)->lockForUpdate()->firstOrFail();
            if ($import->undone_at !== null) {
                return 'この取り込みはすでに取り消しています。';
            }

            $count  = $import->records()->count();
            $edited = $import->records()->whereNotNull('updated_by')->count();
            $shown  = (int) $validated['count_shown'];
            $shownEdited = (int) $validated['edited_shown'];
            if ($count !== $shown || $edited !== $shownEdited) {
                return "この取り込みで足した記録が変わりました（一覧では {$shown} 件・うち手で直した {$shownEdited} 件、今は {$count} 件・うち {$edited} 件）。一覧で確かめてから、もう一度取り消してください。";
            }

            $import->records()->delete();
            $import->update(['undone_at' => now(), 'undone_by' => $request->user()->id, 'undone_count' => $count]);
            SettingLogger::record('paper.import_undone', 'approval_paper_import', $import->id, [], [
                'file_name'   => $import->file_name,
                'fiscal_year' => $import->fiscal_year,
                'deleted'     => $count,
                'edited'      => $edited,
            ]);

            return $count;
        });

        if (is_string($result)) {
            return redirect()->route('approvals.admin.papers.index')->with('error', $result);
        }

        return redirect()->route('approvals.admin.papers.index')
            ->with('success', "取り込みを取り消しました（{$result} 件を消しました）。次の番号は戻していません。戻すときは部門の管理で開始番号を直してください。");
    }

    /**
     * 確認の画面で「足す」とした決裁No（hidden の numbers・「,」区切り）のうち、読み直した結果から消えたもので、いまシステムの申請が
     * 持っている番号（最後の点検 I-1）。消えた番号が無ければ問い合わせない。
     * ⚠ 断るためにだけ使う（書き込みには使わない。送り直された値が何であれ、本人が断られるだけ）。申請が持っていない番号（紙の側で
     *   消えた＝ほかの人が先に登録した）は拾わない＝今までどおり、足さないだけ
     *
     * @param array{records: list<array{number: string}>} $analysis 読み直した結果
     * @return list<string> 申請が使った決裁No（番号の順）
     */
    private function numbersTakenSince(string $shown, array $analysis): array
    {
        $vanished = array_diff(array_filter(explode(',', $shown), fn (string $number): bool => $number !== ''), array_column($analysis['records'], 'number'));
        if ($vanished === []) {
            return [];
        }

        return ApprovalRequest::whereIn('number', array_values($vanished))->orderBy('number')->pluck('number')->all();
    }

    /** 確認の画面（中身を作って描く） */
    private function confirmation(string $content, string $fileName, int $year, array $links): View|RedirectResponse
    {
        try {
            $analysis = $this->analyze($content, $year, $links);
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        }

        return $this->confirmationView($analysis, $content, $fileName, $year, $links);
    }

    private function confirmationView(array $analysis, string $content, string $fileName, int $year, array $links): View
    {
        return view('approvals.admin.papers.import', [
            'years'       => ApprovalPaperRecord::yearChoices(),
            'analysis'    => $analysis,
            'ledgerData'  => base64_encode($content),
            'fileName'    => $fileName,
            'year'        => $year,
            'links'       => $links,
            'importToken' => OneTimeAction::issue(),
        ]);
    }

    /**
     * ファイルの中身を一時ファイルに書いて読む（PhpSpreadsheet はファイルの場所から読む）。読んだら消す
     *
     * @throws PaperImportException
     */
    private function analyze(string $content, int $year, array $links): array
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-ledger');
        try {
            file_put_contents($path, $content);

            return PaperImportAnalysis::analyze($path, $year, $links);
        } finally {
            @unlink($path);
        }
    }
}
