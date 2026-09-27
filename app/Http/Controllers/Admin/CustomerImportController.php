<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use App\Support\CsvImportReader;
use App\Support\OneTimeAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 顧客 CSV インポート（`/admin/customers/import`。経営層のみ）。
 *
 * プレビューと確定を 1 つの `execute()` で受ける（`confirmed` の hidden で分ける）。
 * 確定では、確認画面が `csv_data`（base64）で持ち回った CSV を読み直し、部署・行の検査・重複の確認を
 * すべてやり直す（ブラウザから届いた値は信用しない）。
 *
 * ⚠ 確定（「インポート実行」）は最初のコミット 2046289d から 2026-09-26 まで一度も通っていなかった
 *   （docs/RULES.md Bug #66）。確定のフォームが送るのは `csv_data` なのに `csv_file` を常に必須にしていて、
 *   押すと「CSVファイルは必須です。」で取込の画面へ戻っていた。
 * ⚠ 断るときの戻り先は取込の画面に固定する（`back()` や入力チェックの既定の戻り先＝リファラーを使わない）。
 *   今は確認画面の URL が取込の画面と同じなので 405 にはならないが、ほかの取込と同じ形にそろえる（Bug #64）。
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-27-customer-import-double-submit-design.md §4.3）。
 *   鍵は部署の検査の直後・CSV を読み直す前に使う。書き込みの直前（決裁の取込と同じ位置）に置くと、
 *   1 回目のあとに届いたチェックなしの 2 回目は、1 回目で入った人を重複候補と数えて、鍵より先に
 *   0 件の歯止めに着く（同じ確認画面の 2 回目なのに「プレビューのあとに、同じ人が登録された可能性」の
 *   案内が出る。改修前の文言はチェックを勧めていて、従うと全員がもう一度入った）。
 */
class CustomerImportController extends Controller
{
    /**
     * インポート画面表示
     */
    public function showForm()
    {
        return view('admin.customers.import');
    }

    /**
     * CSVインポート（プレビューと確定）
     */
    public function execute(Request $request)
    {
        try {
            $request->validate([
                'department' => 'required|in:housing,realestate',
            ], [], [
                // 画面ラベルに合わせる（既定は「部署」）
                'department' => 'インポート先部署',
            ]);
        } catch (ValidationException $e) {
            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('admin.customers.import'));
        }

        $department = $request->input('department');
        $confirmed  = $request->boolean('confirmed');
        $skipDupes  = !$request->boolean('include_duplicates');

        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ ほかの検査より先に使う
        if ($confirmed && ! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('admin.customers.import')
                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。');
        }

        $content = $this->loadCsv($request, $confirmed);

        $lines = array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        });

        if (count($lines) < 2) {
            return redirect()->route('admin.customers.import')->with('error', 'CSVファイルにデータがありません。');
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);

        // 設問カラム検出（Q1:xxx, Q2:xxx ...）
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $questionMap = []; // headerIndex => question
        foreach ($header as $hIdx => $hVal) {
            if (preg_match('/^Q(\d+):/', $hVal)) {
                // sort_order順で対応
                $qNum = (int) preg_replace('/^Q(\d+):.*$/', '$1', $hVal);
                if (isset($questions[$qNum - 1])) {
                    $questionMap[$hIdx] = $questions[$qNum - 1];
                }
            }
        }

        // カラムインデックスマッピング
        $colMap = [];
        foreach ($header as $hIdx => $hVal) {
            $cleanHeader = preg_replace('/^Q\d+:/', '', $hVal);
            $cleanHeader = trim($cleanHeader);
            if (isset(BuyerCsvRow::COLUMNS[$cleanHeader])) {
                $colMap[BuyerCsvRow::COLUMNS[$cleanHeader]] = $hIdx;
            }
        }

        $rowErrors = [];
        $dupeRows  = [];
        $validRows = [];
        $rowNum    = 1;

        foreach ($lines as $line) {
            $rowNum++;
            $cols = str_getcsv($line);

            $values = [];
            foreach ($colMap as $key => $hIdx) {
                $values[$key] = $cols[$hIdx] ?? '';
            }
            $row = BuyerCsvRow::from($values);

            // 1 行の誤りはすべて 1 件にまとめて出す（BuyerCsvRow の docblock）
            if ($row->hasErrors()) {
                $rowErrors[] = ['row' => $rowNum, 'message' => $row->errorMessage()];
                continue;
            }

            // 重複チェック
            $lastName   = $row->buyer['last_name'];
            $firstName  = $row->buyer['first_name'];
            $prefecture = $row->buyer['prefecture'] ?? '';
            $city       = $row->buyer['city'] ?? '';
            $existing   = Buyer::where('last_name', $lastName)
                ->where('first_name', $firstName)
                ->where('prefecture', $prefecture)
                ->where('city', $city)
                ->first();

            if ($existing) {
                $dupeRows[] = [
                    'row'         => $rowNum,
                    'name'        => "{$lastName} {$firstName}",
                    'address'     => "{$prefecture}{$city}",
                    'existing_id' => $existing->id,
                ];
                if ($skipDupes) {
                    continue;
                }
            }

            // バリデーション通過
            $validRows[] = [
                'row'  => $row,
                'cols' => $cols,
            ];
        }

        // プレビューモード（確認前）
        if (!$confirmed) {
            return view('admin.customers.import', [
                'preview'    => true,
                'department' => $department,
                'totalRows'  => count($lines),
                'validCount' => count($validRows),
                'rowErrors'  => $rowErrors,
                'dupeRows'   => $dupeRows,
                'csvData'    => base64_encode($content),
                // 確定を 1 回だけ通す鍵（クラスの docblock）
                'importToken' => OneTimeAction::issue(),
            ]);
        }

        // 画面はプレビューのあと DB が変わっていなければ 0 件の確定を出さない（V＝0 ならボタンを隠す）。
        // ここに来るのは、プレビューのあとに同じ人が登録されたとき（別のタブ・ほかの人）と、細工した送信だけ
        // （同じ確認画面からの 2 回目は、上の鍵が先に断る）。
        // ⚠ 重複候補があってもチェックは勧めない（入れると、先に登録された人がもう一度入る）
        if ($validRows === []) {
            $message = $dupeRows !== []
                ? '取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。'
                : 'インポート可能なデータがありません。CSVを修正してください。';

            return redirect()->route('admin.customers.import')->with('error', $message);
        }

        // インポート実行
        DB::beginTransaction();
        try {
            $imported = 0;
            foreach ($validRows as $valid) {
                $row  = $valid['row'];
                $cols = $valid['cols'];

                $buyer = Buyer::create($row->buyer);

                // 部署ピボット（取得日は BuyerCsvRow が Y-m-d にそろえた値）
                $buyer->addToDepartment($department, $row->acquiredDate);

                // アンケート（設問があり、回答データがある場合）
                if (!empty($questionMap)) {
                    $hasAnswer = false;
                    foreach ($questionMap as $hIdx => $q) {
                        if (isset($cols[$hIdx]) && trim($cols[$hIdx]) !== '') {
                            $hasAnswer = true;
                            break;
                        }
                    }

                    if ($hasAnswer) {
                        $surveyData = [
                            'buyer_id'    => $buyer->id,
                            'department'  => $department,
                            'survey_date' => $row->acquiredDate,
                        ];

                        // 分譲地
                        if ($row->projectName) {
                            $project = DB::table('re_projects')
                                ->where('project_name', 'like', $row->projectName . '%')
                                ->first();
                            if ($project) {
                                $surveyData['project_id'] = $project->id;
                            }
                        }

                        // 担当者
                        if ($row->staffName) {
                            $surveyData['staff_name'] = $row->staffName;
                            $staffUser = User::baseUsers()->where('name', 'like', '%' . $row->staffName . '%')->first();
                            if ($staffUser) {
                                $surveyData['staff_user_id'] = $staffUser->id;
                            }
                        }

                        $survey = BuyerSurvey::create($surveyData);

                        foreach ($questionMap as $hIdx => $q) {
                            $val = trim($cols[$hIdx] ?? '');
                            if ($val === '') {
                                continue;
                            }
                            $survey->answers()->create([
                                'question_id'       => $q->id,
                                'answer_value'      => $val,
                                'question_snapshot' => $q->toSnapshot(),
                            ]);
                        }
                    }
                }

                $imported++;
            }

            DB::commit();

            return redirect()->route('admin.customers.import')
                ->with('success', "{$imported}件のインポートが完了しました。");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('admin.customers.import')->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * テンプレートCSVダウンロード
     */
    public function downloadTemplate(Request $request)
    {
        $department = $request->input('department', 'housing');

        $headers = ['姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数',
                     '郵便番号', '都道府県', '市区町村', '住所詳細', '建物名', '電話番号',
                     'メールアドレス', '職業', '勤務先', '勤続年数', '取得日'];

        if ($department === 'housing') {
            $headers[] = '来場分譲地名';
        }
        $headers[] = '担当者名';

        // 設問ヘッダー
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $qNum = 1;
        foreach ($questions as $q) {
            $headers[] = "Q{$qNum}:{$q->label}";
            $qNum++;
        }

        $deptLabel = ($department === 'housing') ? '住宅事業' : '不動産事業';
        $filename  = "顧客インポートテンプレート_{$deptLabel}.csv";

        $bom = "\xEF\xBB\xBF";
        $csv = $bom . implode(',', $headers) . "\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * CSV の中身（UTF-8・BOM なし）を返す。
     *
     * 確定なら、確認画面が持ち回った base64 から読み直す（プレビューで UTF-8 にそろえ BOM を除いた後の内容なので、
     * 変換し直さない）。無い・まったく読めない値は空文字になり、呼び出し元の「データがありません」で止まる。
     * ⚠ 一部だけ壊れている場合は空文字にならない —— `base64_decode()`（strict でない）は、
     *   壊れる手前まで読めた分をそのまま返す。行は呼び出し元がすべて検査し直すので、
     *   プレビューで通らない行はこの経路でも取り込まれない。
     */
    private function loadCsv(Request $request, bool $confirmed): string
    {
        if ($confirmed) {
            return (string) base64_decode((string) $request->input('csv_data', ''));
        }

        try {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);
        } catch (ValidationException $e) {
            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('admin.customers.import'));
        }

        return CsvImportReader::decode(file_get_contents($request->file('csv_file')->getRealPath()));
    }
}
