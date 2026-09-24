<?php

namespace App\Http\Controllers\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\Approval\LoginGuide;
use App\Support\Approval\SettingLogger;
use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use App\Support\CsvImportTemplate;
use App\Support\InitialPassword;
use App\Support\LoginId;
use App\Support\OneTimeAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 社員の CSV 一括登録（設計書 §5.10）。
 *
 * 流れ: アップロード → 確認画面 → 描画された「取り込む」フォーム（hidden の CSV と 1 回限りの鍵）で確定
 *   → **確定時にサーバーで検査をやり直す**（hidden を信用しない）→ 結果＝ログイン案内の画面
 *
 * ⚠ 断るときの戻り先は**取込の画面に固定する**（`back()` や検証エラーの既定の戻り先を使わない）。
 *   確認画面は POST の応答で、その URL は POST 専用の preview。そこに載ったフォーム（アップロードし直し・確定）から
 *   送るとリファラーが preview になり、`url()->previous()` はリファラーを優先するので GET で 405 になる
 *   （docs/RULES.md Bug #64）。
 *
 * ⚠ 行エラーの view のキーは `rowErrors`。`errors` にすると Blade の `$errors`（ViewErrorBag）を
 *   上書きして `Call to a member function any() on array` で 500 する（Bug #53）。
 * ⚠ エラー（`rowErrors`）と注意（`warnings`）は別の入れ物にする（Bug #54 ④）。
 */
class UserImportController extends Controller
{
    private const COLUMN_MAP = [
        '社員番号'       => 'employee_number',
        '氏名'           => 'name',
        'メールアドレス' => 'email',
        '所属部門'       => 'departments',
    ];

    public function form()
    {
        return view('approvals.admin.users.import');
    }

    public function template()
    {
        return CsvImportTemplate::response(
            array_keys(self::COLUMN_MAP),
            [
                ['A0001', '甲 一郎', 'ichiro@mitsuwat.co.jp', 'RE'],
                ['A0002', '乙 二郎', '', 'RE,SA'],
            ],
            'approval_users_template.csv',
        );
    }

    public function preview(Request $request)
    {
        // ⚠ `max:10240`（10MB）は既存の取込 4 本すべてと同じ。無いと、行数の上限
        //    （`csv_max_rows`）を見る前に `parse()` がファイル全体を丸ごと配列へ広げる。
        // ⚠ ルールの配列は**行を分けて閉じる**（`validate([` … 改行 `],`）。
        //    `JapaneseValidationMessagesTest` の走査が `validate(\s*\[(.*?)\n\s*\]\s*[,)]` で
        //    切り出すので、1 行に畳むと**次の配列まで飲み込み**、メッセージの `csv_file.required`
        //    まで「和名の無い項目」として報告される（実測）。既存の取込 4 本も同じ書き方。
        try {
            $request->validate([
                'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            ], [
                'csv_file.required' => 'CSVファイルを選択してください。',
                'csv_file.mimes'    => 'CSVファイルを選択してください。',
            ], [
                'csv_file' => 'CSVファイル',
            ]);
        } catch (ValidationException $e) {
            // 確認画面からアップロードし直したときも取込の画面へ戻す（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('approvals.admin.users.import'));
        }

        $content = CsvImportReader::decode($request->file('csv_file')->get());

        try {
            $analysis = $this->analyze($content);
        } catch (CsvImportException $e) {
            return redirect()->route('approvals.admin.users.import')->with('error', $e->getMessage());
        }

        return view('approvals.admin.users.import', array_merge($analysis, [
            'csvData'     => base64_encode($content),
            'guideToken'  => OneTimeAction::issue(),
        ]));
    }

    public function execute(Request $request)
    {
        try {
            $request->validate([
                'csv_data' => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            // 確定のフォームも確認画面（POST の応答）に載っている。取込の画面へ戻す（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('approvals.admin.users.import'));
        }

        $content = base64_decode((string) $request->input('csv_data'), true);

        if ($content === false) {
            return redirect()->route('approvals.admin.users.import')->with('error', '取り込むデータを読み取れませんでした。もう一度アップロードしてください。');
        }

        try {
            // ⚠ 確定でも検査をやり直す（画面が送ってきた hidden を信用しない）
            $analysis = $this->analyze($content);
        } catch (CsvImportException $e) {
            return redirect()->route('approvals.admin.users.import')->with('error', $e->getMessage());
        }

        if ($analysis['rowErrors'] !== []) {
            return redirect()->route('approvals.admin.users.import')
                ->with('error', '取り込めない行があります。もう一度アップロードして内容を確認してください。');
        }

        // ⚠ ここは**到達しない**。`parse()` が 2 行未満のファイルを断り、`analyze()` のループは
        //   どの経路でも必ず `rows` か `rowErrors` に 1 つ積むので、`validCount === 0` なら
        //   `rowErrors !== []` になって 1 つ上の `if` が先に断る。それでも残すのは、行の検査を
        //   変えたときにここが最後の歯止めになるため。
        //   ⚠ **到達しないのでテストでは赤にできない**（「ここも守られている」と読み違えない
        //     こと。Bug #48。`Approval\UserController::toggleStatus()` に同じ形の注記がある）。
        if ($analysis['validCount'] === 0) {
            return redirect()->route('approvals.admin.users.import')->with('error', '取り込む行がありません。');
        }

        // ⚠ 鍵の受け取りは OneTimeAction::claimFrom() に一本化されている
        //    （配列トークンで 500 にしない理由はそちらの docblock）。
        if (! OneTimeAction::claimFrom($request)) {
            return redirect()->route('approvals.admin.users.import')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度アップロードしてください。');
        }

        $entries = DB::transaction(fn () => $this->apply($analysis['rows']));

        // ⚠ 新しく登録した人が 1 人もいないなら、ログイン案内を返さない。印刷する初期
        //   パスワードが 1 つも無いのに「この画面を閉じると初期パスワードは二度と表示
        //   されません」だけが出て、誤解を招くため。更新は済んでいるので成功として戻す。
        if ($analysis['createCount'] === 0) {
            return redirect()->route('approvals.admin.users.import')
                ->with('success', "{$analysis['updateCount']} 件の社員番号と所属部門を更新しました。新しく登録した人がいないため、ログイン案内はありません。");
        }

        // 新規登録では通知メールを送らない（紙で渡す。要件 8.1 に無い）ので、件数も渡さない（帯に出さない。F7）
        // 戻り先: 確定のフォームは確認画面（preview の POST の応答）に載っている＝リファラーは POST 専用の URL
        //   （押すと 405 だった。F2）。元の画面＝取込の画面を明示する
        return (new LoginGuide($entries, backUrl: route('approvals.admin.users.import')))->toResponse($request);
    }

    /**
     * 行ごとに検査して、取り込む行・エラー・注意に分ける。
     *
     * ⚠ **エラー文の「行N」は、空行を挟んだ CSV では実ファイルの行番号と一致しない。**
     *   `CsvImportReader::parse()` が空行を**先に捨てる**ので、`$index + 2` は「空行を除いた
     *   何行目か」になる。既存の取込 4 本（顧客・テナント・賃貸マンション・工程表）も
     *   まったく同じ振る舞いなので、ここだけ直すと画面ごとに数え方が変わる。よって直さない。
     *
     * ⚠ **このメソッドは `CLAUDE.md` の目安（50 行）を超えている。** 分けるなら
     *   `validateRow()`（エラーにする行の判定）と `warningsFor()`（取り込むと決めた行に積む注意）
     *   が候補。⚠ ただし**先に挙動をテストで固定してから**割ること（分割そのものが
     *   `continue` の位置を動かす変更なので、守り手がいない状態で割ると無音で壊れる）。
     *
     * @return array{rows: list<array>, rowErrors: list<array>, warnings: list<array>, validCount: int, createCount: int, updateCount: int, totalRows: int}
     */
    private function analyze(string $content): array
    {
        $raw = CsvImportReader::parse($content, self::COLUMN_MAP, array_values(self::COLUMN_MAP));

        $max = (int) config('approval.csv_max_rows');
        if (count($raw) > $max) {
            throw new CsvImportException("1 回に取り込めるのは {$max} 行までです（今回は " . count($raw) . ' 行）。分けてから取り込んでください。');
        }

        $departments  = ApprovalDepartment::pluck('id', 'code');
        $hasDomain    = ApprovalMailDomain::exists();
        $seenNumbers  = [];
        $seenEmails   = [];
        $seenExisting = [];
        $matches      = [];

        // 同じファイルの中の重複を先に数える（重なった行は両方エラーにする）
        //
        // ⚠ **既存の利用者に当たった回数もここで数える。** 社員番号どうし・メールアドレス
        //   どうしの重複だけを見ていると、「行1 が社員番号で X さん・行2 がメールアドレスで
        //   同じ X さん」に当たる 2 行が**どちらも取り込む行**として通り、`apply()` が順に
        //   適用して**後の行が前の行を無音で上書きする**（更新の件数も実人数と食い違う）。
        //   一意の索引には当たらないので DB も止めてくれない。
        foreach ($raw as $index => $row) {
            $number = LoginId::normalize($row['employee_number']);
            $email  = LoginId::normalize($row['email']);
            if ($number !== '') { $seenNumbers[$number] = ($seenNumbers[$number] ?? 0) + 1; }
            if ($email !== '')  { $seenEmails[$email] = ($seenEmails[$email] ?? 0) + 1; }

            // 既存との照合（削除済みも含めて引く）。1 行につき 1 回だけ引き、下の本体で使い回す
            $byNumber = $number === '' ? null : User::withTrashed()->where('employee_number', $number)->first();
            $byEmail  = $email === ''  ? null : User::withTrashed()->where('email', $email)->first();
            $matches[$index] = [$byNumber, $byEmail];

            // 別人に当たる行はそれ自体がエラーになるので、ここでは数えない
            $existing = $this->existingFor($byNumber, $byEmail);
            if ($existing !== null) { $seenExisting[$existing->id] = ($seenExisting[$existing->id] ?? 0) + 1; }
        }

        $digitWidths = $this->numericWidths(array_keys($seenNumbers));

        $rows = $rowErrors = $warnings = [];
        $createCount = $updateCount = 0;

        foreach ($raw as $index => $row) {
            $line   = $index + 2; // 1 行目は見出し
            $number = LoginId::normalize($row['employee_number']);
            $email  = LoginId::normalize($row['email']);
            $name   = trim($row['name']);

            if ($number === '') {
                $rowErrors[] = ['row' => $line, 'message' => '社員番号が空です'];
                continue;
            }
            if (! preg_match(LoginId::EMPLOYEE_NUMBER_PATTERN, $number)) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号「{$row['employee_number']}」は英数字とハイフン 20 文字までで入力してください"];
                continue;
            }
            if ($name === '' || mb_strlen($name) > 100) {
                $rowErrors[] = ['row' => $line, 'message' => '氏名が空、または 100 文字を超えています'];
                continue;
            }
            if (($seenNumbers[$number] ?? 0) > 1) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号「{$number}」が同じファイルの中で重複しています"];
                continue;
            }
            if ($email !== '' && ($seenEmails[$email] ?? 0) > 1) {
                $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」が同じファイルの中で重複しています"];
                continue;
            }
            if ($email !== '') {
                if (! $hasDomain) {
                    $rowErrors[] = ['row' => $line, 'message' => '許可するメールのドメインが登録されていません（部門の管理で登録してください）'];
                    continue;
                }
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」の形式が正しくありません"];
                    continue;
                }
                if (! ApprovalMailDomain::allows($email)) {
                    $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」は許可されていないドメインです"];
                    continue;
                }
            }

            $codes = $this->splitDepartmentCodes($row['departments']);
            if ($codes === []) {
                $rowErrors[] = ['row' => $line, 'message' => '所属部門を 1 つ以上入力してください'];
                continue;
            }
            $unknown = array_values(array_diff($codes, $departments->keys()->all()));
            if ($unknown !== []) {
                $rowErrors[] = ['row' => $line, 'message' => '登録されていない部門です: ' . implode('、', $unknown)];
                continue;
            }

            // 既存との照合（先回りで引いた結果を使う）
            [$byNumber, $byEmail] = $matches[$index];

            if ($this->matchesTwoPeople($byNumber, $byEmail)) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号は {$byNumber->name} さん、メールアドレスは {$byEmail->name} さんと一致します"];
                continue;
            }

            $existing = $this->existingFor($byNumber, $byEmail);

            if ($existing && $existing->trashed()) {
                $rowErrors[] = ['row' => $line, 'message' => "削除済みの利用者（{$existing->name}さん）と一致します。基幹の管理者に復元を依頼してください"];
                continue;
            }

            // 1 行 1 人。同じ人に当たる行が 2 つ以上あれば、どちらもエラーにする
            if ($existing && ($seenExisting[$existing->id] ?? 0) > 1) {
                $rowErrors[] = ['row' => $line, 'message' => "ほかの行と同じ利用者（{$existing->name}さん）に一致します"];
                continue;
            }

            // ここから先は取り込むと決めた行。注意はこの位置でだけ積む
            if ($existing) {
                $updateCount++;

                // ⚠ 社員番号が**まだ無い人**（メールアドレスでログインしていた人）に
                //   メールアドレスで当たった行では `employee_number` が null になる。
                //   同じ文を使うと「ログインIDが  から A0001 に変わります」と空白が出る。
                $currentNumber = (string) $existing->employee_number;
                if ($currentNumber !== $number) {
                    $warnings[] = ['row' => $line, 'message' => $currentNumber === ''
                        ? "ログインIDに社員番号 {$number} を設定します"
                        : "ログインIDが {$currentNumber} から {$number} に変わります"];
                }
                if ($existing->name !== $name) {
                    $warnings[] = ['row' => $line, 'message' => "氏名が登録と違います（変えません）: 登録「{$existing->name}」/ CSV「{$name}」"];
                }
                if ($email !== '' && $existing->email !== $email) {
                    $warnings[] = ['row' => $line, 'message' => 'メールアドレスは変えません（変更は基幹の管理者に依頼してください）'];
                }
                if (! $existing->isActive()) {
                    $warnings[] = ['row' => $line, 'message' => '無効の利用者です（有効化は利用者の管理で行ってください）'];
                }
            } else {
                $createCount++;

                if (isset($digitWidths['max'], $digitWidths['widths'][$number]) && $digitWidths['widths'][$number] < $digitWidths['max']) {
                    $warnings[] = ['row' => $line, 'message' => "社員番号 {$number} は、ほかの行より桁が少ないです。Excel で先頭の 0 が落ちていませんか"];
                }
            }

            $rows[] = [
                'line'            => $line,
                'existing_id'     => $existing?->id,
                'employee_number' => $number,
                'name'            => $name,
                'email'           => $email === '' ? null : $email,
                'department_ids'  => $departments->only($codes)->values()->all(),
                'department_codes'=> $codes,
            ];
        }

        return [
            'rows'        => $rows,
            'rowErrors'   => $rowErrors,
            'warnings'    => $warnings,
            'validCount'  => count($rows),
            'createCount' => $createCount,
            'updateCount' => $updateCount,
            'totalRows'   => count($raw),
        ];
    }

    /** 取り込む（新規は作り、既存は社員番号と所属部門だけ書き換える） */
    private function apply(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if ($row['existing_id'] !== null) {
                $user   = User::findOrFail($row['existing_id']);
                $before = ['employee_number' => $user->employee_number];

                $user->employee_number = $row['employee_number'];
                $user->save();

                $user->approvalDepartments()->sync($row['department_ids']);

                SettingLogger::record('user.imported_updated', 'user', $user->id, $before, [
                    'employee_number' => $user->employee_number, 'departments' => $row['department_codes'],
                ]);

                continue;
            }

            $password = InitialPassword::generate();

            $user = new User();
            $user->name = $row['name'];
            $user->employee_number = $row['employee_number'];
            $user->email = $row['email'];
            $user->role = UserRole::ApprovalOnly->value;
            $user->status = UserStatus::Active->value;
            // ⚠ 強度 10 のハッシュは hashed キャストとぶつかるので、必ず User::setInitialPassword() を通す
            //    （must_change_password もその中で立つ。理由はそのメソッドの docblock）
            $user->setInitialPassword($password);
            $user->save();

            $user->approvalDepartments()->sync($row['department_ids']);

            SettingLogger::record('user.imported_created', 'user', $user->id, [], [
                'name' => $user->name, 'employee_number' => $user->employee_number,
                'email' => $user->email, 'departments' => $row['department_codes'],
            ]);

            $entries[] = ['user' => $user, 'password' => $password];
        }

        return $entries;
    }

    /**
     * 社員番号とメールアドレスが**別人**に当たる行か。
     *
     * ⚠ **この規則はここにしか書かない。** 前処理（`$seenExisting` を数える側）と本体
     *   （エラーを出す側）が同じ判断をする必要があり、別々に書くと片方だけ直したときに
     *   **件数と実際の判定が無音でずれる**（Bug #41）。
     */
    private function matchesTwoPeople(?User $byNumber, ?User $byEmail): bool
    {
        return $byNumber !== null && $byEmail !== null && $byNumber->id !== $byEmail->id;
    }

    /** その行が当たる既存の利用者（別人に当たる行は、それ自体がエラーなので null を返す） */
    private function existingFor(?User $byNumber, ?User $byEmail): ?User
    {
        return $this->matchesTwoPeople($byNumber, $byEmail) ? null : ($byNumber ?? $byEmail);
    }

    /**
     * 所属部門の並びを分ける。**英字以外はすべて区切りとみなす**
     * （カンマ・スラッシュ・空白・読点・中黒。全角も可）。
     */
    private function splitDepartmentCodes(string $value): array
    {
        $value = mb_strtoupper(mb_convert_kana($value, 'as'), 'UTF-8');
        $parts = preg_split('/[^A-Z]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($parts ?: []));
    }

    /**
     * 数字だけの社員番号の桁数（Excel が先頭の 0 を落とした行を見つけるため）。
     *
     * @return array{max: int|null, widths: array<string, int>}
     */
    private function numericWidths(array $numbers): array
    {
        $widths = [];
        foreach ($numbers as $number) {
            // ⚠ **必ず文字列に戻す。** 呼び出し側は `$seenNumbers` のキーを渡すが、PHP は
            //    「整数として書ける」文字列のキーを**整数へ勝手に変える**（`'125'` → `int 125`。
            //    `'00123'` は先頭の 0 があるので文字列のまま）。整数のまま `ctype_digit()` に
            //    渡すと、PHP はそれを ASCII の符号位置とみなして判定する（125 は `}` ＝ false）。
            //    つまり**先頭の 0 が落ちた行だけが桁数の一覧から漏れ、この注意が永久に出ない**
            //    （この機能が見つけたい行そのもの）。実測で確認済み。
            $number = (string) $number;

            if (ctype_digit($number)) {
                $widths[$number] = strlen($number);
            }
        }

        return ['max' => $widths === [] ? null : max($widths), 'widths' => $widths];
    }
}
