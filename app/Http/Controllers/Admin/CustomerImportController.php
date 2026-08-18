<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * CSVインポート実行
     */
    public function execute(Request $request)
    {
        $request->validate([
            'csv_file'   => 'required|file|mimes:csv,txt|max:10240',
            'department' => 'required|in:housing,realestate',
        ], [], [
            // 画面ラベルに合わせる（既定は「部署」）
            'department' => 'インポート先部署',
        ]);

        $department  = $request->input('department');
        $skipDupes   = !$request->boolean('include_duplicates');

        // CSV読み込み
        $file = $request->file('csv_file');
        $content = file_get_contents($file->getRealPath());

        // Shift_JIS自動判定
        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        // BOM除去
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        });

        if (count($lines) < 2) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);

        // 基本カラムマッピング
        $baseColumns = [
            '姓' => 'last_name', '名' => 'first_name',
            'セイ' => 'last_name_kana', 'メイ' => 'first_name_kana',
            '生年月日' => 'birth_date', '元号' => 'birth_era',
            '大人人数' => 'family_adults', '子供人数' => 'family_children',
            '郵便番号' => 'postal_code', '都道府県' => 'prefecture',
            '市区町村' => 'city', '住所詳細' => 'address_detail',
            '建物名' => 'building_name', '電話番号' => 'phone',
            'メールアドレス' => 'email', '職業' => 'occupation',
            '勤務先' => 'employer', '勤続年数' => 'years_employed',
            '取得日' => 'acquired_date', '来場分譲地名' => 'project_name',
            '担当者名' => 'staff_name',
        ];

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
            if (isset($baseColumns[$cleanHeader])) {
                $colMap[$baseColumns[$cleanHeader]] = $hIdx;
            }
        }

        $errors    = [];
        $dupeRows  = [];
        $validRows = [];
        $rowNum    = 1;

        foreach ($lines as $line) {
            $rowNum++;
            $cols = str_getcsv($line);

            // 姓チェック
            $lastName = trim($cols[$colMap['last_name'] ?? -1] ?? '');
            $firstName = trim($cols[$colMap['first_name'] ?? -1] ?? '');
            if (!$lastName) {
                $errors[] = ['row' => $rowNum, 'message' => '姓が未入力です'];
                continue;
            }
            if (!$firstName) {
                $errors[] = ['row' => $rowNum, 'message' => '名が未入力です'];
                continue;
            }

            // 取得日チェック
            $acquiredDate = trim($cols[$colMap['acquired_date'] ?? -1] ?? '');
            $acquiredDate = str_replace('/', '-', $acquiredDate);
            if (!$acquiredDate) {
                $errors[] = ['row' => $rowNum, 'message' => '取得日が未入力です'];
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquiredDate) || !strtotime($acquiredDate)) {
                $errors[] = ['row' => $rowNum, 'message' => "取得日の日付形式が不正です（{$acquiredDate}）"];
                continue;
            }

            // 重複チェック
            $prefecture = trim($cols[$colMap['prefecture'] ?? -1] ?? '');
            $city       = trim($cols[$colMap['city'] ?? -1] ?? '');
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
            $rowData = [
                'row'  => $rowNum,
                'cols' => $cols,
            ];
            $validRows[] = $rowData;
        }

        // プレビューモード（確認前）
        if (!$request->boolean('confirmed')) {
            return view('admin.customers.import', [
                'preview'    => true,
                'department' => $department,
                'totalRows'  => count($lines),
                'validCount' => count($validRows),
                'rowErrors'  => $errors,
                'dupeRows'   => $dupeRows,
                'csvData'    => base64_encode($content),
            ]);
        }

        // インポート実行
        DB::beginTransaction();
        try {
            $imported = 0;
            foreach ($validRows as $row) {
                $cols = $row['cols'];

                $buyerData = [];
                foreach ($baseColumns as $jpName => $dbCol) {
                    if ($dbCol === 'acquired_date' || $dbCol === 'project_name' || $dbCol === 'staff_name') {
                        continue;
                    }
                    if (isset($colMap[$dbCol]) && isset($cols[$colMap[$dbCol]])) {
                        $val = trim($cols[$colMap[$dbCol]]);
                        if ($val !== '') {
                            $buyerData[$dbCol] = $val;
                        }
                    }
                }

                // birth_date変換
                if (isset($buyerData['birth_date'])) {
                    $buyerData['birth_date'] = str_replace('/', '-', $buyerData['birth_date']);
                }

                $buyer = Buyer::create($buyerData);

                // 部署ピボット
                $acquiredDate = str_replace('/', '-', trim($cols[$colMap['acquired_date'] ?? -1] ?? ''));
                $buyer->addToDepartment($department, $acquiredDate);

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
                            'survey_date' => $acquiredDate,
                        ];

                        // 分譲地
                        $projectName = trim($cols[$colMap['project_name'] ?? -1] ?? '');
                        if ($projectName) {
                            $project = DB::table('re_projects')
                                ->where('project_name', 'like', $projectName . '%')
                                ->first();
                            if ($project) {
                                $surveyData['project_id'] = $project->id;
                            }
                        }

                        // 担当者
                        $staffName = trim($cols[$colMap['staff_name'] ?? -1] ?? '');
                        if ($staffName) {
                            $surveyData['staff_name'] = $staffName;
                            $staffUser = User::where('name', 'like', '%' . $staffName . '%')->first();
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
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
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
}
