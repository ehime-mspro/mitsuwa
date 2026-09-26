<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use App\Support\CsvImportReader;
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
            ]);
        }

        // 取り込む行が 0 件の確定は書かずに断る（画面はそのときボタンを出さないが、JS が動かなくても止める）。
        // 重複候補があるのに 0 件なのは、チェックが入っていないとき（入っていれば重複候補も取り込む行に入る）
        if ($validRows === []) {
            $message = $dupeRows !== []
                ? '取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。'
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
     * 変換し直さない）。壊れていても無くても空文字になり、呼び出し元の「データがありません」で止まる。
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
