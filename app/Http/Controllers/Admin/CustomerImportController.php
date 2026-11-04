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
use App\Support\SurveyAnswers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * ⚠ 同じ人（姓・名・都道府県・市区町村が同じ）が登録済みでも、取込先の部署にいなければ重複候補にしない。新しく作らずに、
 *   その人に取込先の部署を足す（顧客は 1 人を部署で共有する。画面の登録の「この部署に取り込む」と同じ。docs/RULES.md Bug #124）。
 *   2026-11-03 まではどの部署の人でも重複候補にしていて、チェックなしでは取込先の部署に入らず、チェックを入れると同じ人が
 *   別の顧客としてもう 1 人できていた。どの部署にもいない人（部署が外れた人）にも足す（利用者の決定）
 * ⚠ 同じ CSV の中の同じ人（登録済みでない人）は、2 行目からを重複候補にする（チェックを入れたときだけ別人として作る。
 *   直す前は 2 行とも新しい顧客になっていた。docs/RULES.md Bug #126）
 * ⚠ 設問の列（`Q{n}:設問の文`）は、番号で「有効な設問を並び順に並べた n 番目」に結びつけたうえで、見出しの文を
 *   その設問の今の文と照らし合わせる。違う列・当たる設問の無い列が 1 つでもあれば、プレビューでも確定でも
 *   ファイルごと断る（設計書 2026-11-02-survey-question-admin-design.md §4.4・D8。並び替え・有効／無効・
 *   設問の文の変更のあとの古いテンプレートで、回答が別の設問へ入るのを防ぐ）。
 */
class CustomerImportController extends Controller
{
    /**
     * 設問の列の見出しの文と設問の文を比べる前に、両側で 1 つの字にそろえる字の組（JIS の対応の字 => Windows の対応の字）。
     * Windows の Excel が Shift_JIS（cp932）で保存したテンプレートを CsvImportReader::decode() が SJIS（JIS の対応）として読むと、
     * 「～」「－」などが別の字（「〜」「−」）になり、ダウンロードし直したテンプレートでも文が合わなくなるため（D8・Ruling 18）。
     * ⚠ 比べるときだけに使う。誤りの文には読んだ見出しと設問の文をそのまま出し、行の値には使わない
     */
    private const SHIFT_JIS_LOOKALIKES = [
        "\u{301C}" => "\u{FF5E}", // 〜 => ～
        "\u{2212}" => "\u{FF0D}", // − => －
        "\u{2016}" => "\u{2225}", // ‖ => ∥
        "\u{2014}" => "\u{2015}", // — => ―
        "\u{00A2}" => "\u{FFE0}", // ¢ => ￠
        "\u{00A3}" => "\u{FFE1}", // £ => ￡
        "\u{00AC}" => "\u{FFE2}", // ¬ => ￢
    ];

    /** 取込先の部署（execute() の入力チェック in:housing,realestate と同じ 2 つ） */
    private const DEPARTMENTS = ['housing', 'realestate'];

    /**
     * インポート画面表示。URL の `?department=` が 2 つの部署のどちらかのときだけ、その部署を選んで開く
     * （設問の列の見出しで断ったときの戻り先。ほかの値は今までどおり部署を渡さない＝住宅事業）
     */
    public function showForm(Request $request)
    {
        $department = $request->query('department');

        return view('admin.customers.import', in_array($department, self::DEPARTMENTS, true) ? ['department' => $department] : []);
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

        // 1 件（レコード）ずつ読む。引用符の中の改行で行を割らない（直す前は `explode("\n")` で割り、値が改行の手前で切れて
        // 保存された。docs/RULES.md Bug #125）。CSV の決まり（RFC 4180。`"` は 2 つ重ねる）で読み、円記号は逃がしの文字にしない
        // （既定の逃がしの文字で読むと、`\"` の続く設問の文＝末尾が円記号の文を引用符で囲んだものが割れる）
        $records = CsvImportReader::records($content);

        if (count($records) < 2) {
            return redirect()->route('admin.customers.import')->with('error', 'CSVファイルにデータがありません。');
        }

        $header = array_map('trim', array_shift($records));

        // 設問カラム検出（Q1:xxx, Q2:xxx ...）。Q の番号は、有効な設問を並び順（テンプレートと同じ）に並べた何番目か
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $questionsById = $questions->keyBy('id'); // 確定で回答の question_snapshot を作る
        $questionMap = []; // headerIndex => question
        $questionNumbers = []; // headerIndex => 見出しの Q の番号（誤りの文の主語に使う）
        $columnProblems = []; // 見出しが今の設問と合わない列の文（クラスの docblock・D8）
        foreach ($header as $hIdx => $hVal) {
            if (preg_match('/^Q(\d+):(.*)/s', $hVal, $m) !== 1) {
                continue;
            }
            $qNum     = (int) $m[1];
            $question = $questions[$qNum - 1] ?? null;
            if ($question === null) {
                $columnProblems[] = "Q{$qNum} の列に当たる設問がありません（今の設問は {$questions->count()} 問です）。";
                continue;
            }
            // 前後の空白（全角の空白も）は除き、Shift_JIS の読み方で変わる字をそろえて比べる（SHIFT_JIS_LOOKALIKES）
            $columnLabel  = Str::trim($m[2]);
            $currentLabel = Str::trim($question->label);
            if (strtr($columnLabel, self::SHIFT_JIS_LOOKALIKES) !== strtr($currentLabel, self::SHIFT_JIS_LOOKALIKES)) {
                $columnProblems[] = "Q{$qNum} の列の設問「{$columnLabel}」が、今の Q{$qNum}「{$currentLabel}」と違います。";
                continue;
            }
            $questionMap[$hIdx] = $question;
            $questionNumbers[$hIdx] = $qNum;
        }

        // 行を検査する前・書き込む前に、ファイルごと断る（確定でも、読み直した CSV で照らし合わせ直す）。
        // 戻り先は同じ部署を選んだ取込の画面（案内どおりダウンロードし直すと、その部署のテンプレートになる）
        if ($columnProblems !== []) {
            return redirect()->route('admin.customers.import', ['department' => $department])->withErrors([
                'csv_file' => [...$columnProblems, '設問の並びや文が変わっています。テンプレートをダウンロードし直してください。'],
            ]);
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
        $addRows   = []; // 取込先の部署にいない同じ人（新しく作らず、その人に取込先の部署を足す。クラスの docblock）
        $validRows = [];
        $addedIds  = []; // この CSV で取込先の部署を足す顧客の id（同じ人の 2 行目からは、その人の重複候補にする）
        $newPeople = []; // この CSV で新しく作る人（姓・名・都道府県・市区町村） => 最初の行の番号（同じ人の 2 行目からは重複候補にする。Bug #126）
        $rowNum    = 1;

        foreach ($records as $cells) {
            $rowNum++;
            // セルの中の改行（Excel の Alt+Enter）は空白 1 つにする。画面の入力欄は 1 行で改行を入れられないので、
            // 画面で直して保存しても変わらない形にそろえる（利用者の決定 2026-11-04。Bug #125）
            $cols = array_map(fn (string $cell) => preg_replace('/(?:\r\n|\r|\n)+/', ' ', $cell), $cells);

            $values = [];
            foreach ($colMap as $key => $hIdx) {
                $values[$key] = $cols[$hIdx] ?? '';
            }
            $row = BuyerCsvRow::from($values);

            // アンケートの列を画面と同じ形にして検査し、保存の形にする（設問 id => answer_value。回答なしの列は入れない）
            $answers = [];
            $answerErrors = [];
            foreach ($questionMap as $hIdx => $q) {
                [$stored, $problems] = SurveyAnswers::fromCsv(
                    $q,
                    (string) ($cols[$hIdx] ?? ''),
                    "Q{$questionNumbers[$hIdx]}（{$q->label}）"
                );
                array_push($answerErrors, ...$problems);
                if ($problems === [] && $stored !== null) {
                    $answers[$q->id] = $stored;
                }
            }

            // 1 行の誤りはすべて 1 件にまとめて出す（BuyerCsvRow の docblock）。アンケートの列の誤りは列の誤りの後ろへ「／」でつなぐ
            if ($row->hasErrors() || $answerErrors !== []) {
                $rowErrors[] = ['row' => $rowNum, 'message' => implode('／', [...$row->errors, ...$answerErrors])];
                continue;
            }

            // 重複チェック
            $lastName   = $row->buyer['last_name'];
            $firstName  = $row->buyer['first_name'];
            $prefecture = $row->buyer['prefecture'] ?? '';
            $city       = $row->buyer['city'] ?? '';
            // 空欄どうしを同じとみなす。空欄は NULL で保存される（BuyerCsvRow は空欄の列を渡さず、画面の登録も空欄を NULL にする）ので、
            // 空文字と比べるだけでは一致せず、上げ直すと同じ人が 2 人になっていた（docs/RULES.md Bug #123）。空文字で入っている行にも当てる
            $sameAs = fn (string $column, string $value) => fn ($query) => $value === ''
                ? $query->whereNull($column)->orWhere($column, '')
                : $query->where($column, $value);
            // ⚠ id の順に並べる（取込先の部署にいなければ、最初に登録された人に足す。本番の MySQL は並びを指定しないと決まらない）
            $matches    = Buyer::with('departments')
                ->where('last_name', $lastName)
                ->where('first_name', $firstName)
                ->where($sameAs('prefecture', $prefecture))
                ->where($sameAs('city', $city))
                ->orderBy('id')
                ->get();
            $address    = "{$prefecture}{$city}" !== '' ? "{$prefecture}{$city}" : '住所なし';
            // CSV の中の同じ人の目印（空欄どうしも同じ。上の DB の比べ方と同じ）
            $personKey  = json_encode([$lastName, $firstName, $prefecture, $city], JSON_INVALID_UTF8_SUBSTITUTE);

            // 取込先の部署にいる人（この CSV の前の行で足す人も含む）に当たれば重複候補
            $inDepartment = $matches->first(fn (Buyer $buyer) => in_array($buyer->id, $addedIds, true)
                || $buyer->departments->contains(fn ($pivot) => $pivot->department->value === $department));

            if ($inDepartment) {
                $dupeRows[] = [
                    'row'         => $rowNum,
                    'name'        => "{$lastName} {$firstName}",
                    'address'     => $address,
                    'existing_id' => $inDepartment->id,
                    'same_as_row' => null,
                ];
                if ($skipDupes) {
                    continue;
                }
            } elseif ($matches->isNotEmpty()) {
                // 取込先の部署にいない同じ人: 新しく作らず、最初に登録された人に取込先の部署を足す（クラスの docblock）
                $existing   = $matches->first();
                $addedIds[] = $existing->id;
                $addRows[]  = [
                    'row'         => $rowNum,
                    'name'        => "{$lastName} {$firstName}",
                    'address'     => $address,
                    'existing_id' => $existing->id,
                    // 今いる部署の名前（どの部署にもいなければ空）
                    'departments' => $existing->departments->map(fn ($pivot) => $pivot->department->label())->implode('・'),
                ];
                $validRows[] = [
                    'rowNum'   => $rowNum,
                    'row'      => $row,
                    'answers'  => $answers,
                    'existing' => $existing,
                ];
                continue;
            } elseif (isset($newPeople[$personKey])) {
                // この CSV の前の行で新しく作る人と同じ人: 重複候補にする（チェックを入れたときだけ、別人として新しく作る）。
                // 直す前は 2 行とも新しい顧客になっていた（docs/RULES.md Bug #126）
                $dupeRows[] = [
                    'row'         => $rowNum,
                    'name'        => "{$lastName} {$firstName}",
                    'address'     => $address,
                    'existing_id' => null,
                    'same_as_row' => $newPeople[$personKey],
                ];
                if ($skipDupes) {
                    continue;
                }
            } else {
                $newPeople[$personKey] = $rowNum;
            }

            // バリデーション通過
            $validRows[] = [
                'rowNum'   => $rowNum,
                'row'      => $row,
                'answers'  => $answers,
                'existing' => null,
            ];
        }

        // プレビューモード（確認前）
        if (!$confirmed) {
            return view('admin.customers.import', [
                'preview'    => true,
                'department' => $department,
                'totalRows'  => count($records),
                'validCount' => count($validRows),
                'rowErrors'  => $rowErrors,
                'dupeRows'   => $dupeRows,
                'addRows'    => $addRows,
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
        $current = null; // 書き込んでいる行の番号（データベースのエラーの案内に出す）
        try {
            $imported = 0;
            $added    = 0;
            foreach ($validRows as $valid) {
                $current = $valid['rowNum'];
                $row     = $valid['row'];
                $answers = $valid['answers'];

                if ($valid['existing'] !== null) {
                    // 取込先の部署にいない同じ人: その人に部署を足す。中身（電話番号など）は書き換えない
                    $buyer = $valid['existing'];
                    $added++;
                } else {
                    $buyer = Buyer::create($row->buyer);
                }

                // 部署ピボット（取得日は BuyerCsvRow が Y-m-d にそろえた値）
                $buyer->addToDepartment($department, $row->acquiredDate);

                // アンケート（検査を通った回答がある場合）
                if ($answers !== []) {
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

                    // 回答は設問 id で畳んだ $answers から作る（1 設問 1 行）。⚠ 見出しの列ごと（$questionMap）に回すと、
                    // 同じ「Q{n}:」の列が 2 つある CSV で同じ設問の回答を 2 回作り、一意制約 uq_survey_answer で全行が巻き戻る
                    foreach ($answers as $questionId => $answerValue) {
                        $survey->answers()->create([
                            'question_id'       => $questionId,
                            'answer_value'      => $answerValue,
                            'question_snapshot' => $questionsById[$questionId]->toSnapshot(),
                        ]);
                    }
                }

                $imported++;
            }

            DB::commit();

            $addedNote = $added > 0 ? "（うち{$added}件は、登録済みの顧客にこの部署を足しました）" : '';

            return redirect()->route('admin.customers.import')
                ->with('success', "{$imported}件のインポートが完了しました{$addedNote}。");
        } catch (\Exception $e) {
            DB::rollBack();
            // 生の SQL の文（英語・名前や電話番号などの入力値を含む）は画面に出さず、記録に残す。直す前は赤い帯にそのまま出し、
            // 記録にも残らなかった（ZEAL 会員の取込〈Bug #72〉・顧客の画面〈Bug #89〉と同じ形）
            report($e);

            return redirect()->route('admin.customers.import')
                ->with('error', "取り込めませんでした（行{$current} まで進んだところでデータベースのエラーが起きました）。1件も登録していません。CSVを確かめて、アップロードし直してください。理由はシステムの記録（laravel.log）に残しています。");
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

        // 見出しは CSV の決まり（RFC 4180）で書く: カンマ・`"`・空白を含む設問の文は引用符で囲み、`"` は 2 つ重ねる
        // （つなぐだけだと、カンマを含む設問の文で列が割れた）。逃がしの文字は使わない（取込の見出しの読み方と同じ）
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '', "\n");
        rewind($stream);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($stream);
        fclose($stream);

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
