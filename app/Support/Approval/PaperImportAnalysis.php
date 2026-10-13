<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Support\JapanTime;
use Illuminate\Support\Collection;

/**
 * 紙の決裁台帳の取り込みの中身（要件 11.1〜11.4・段階5 設計書 §5.9・D6・D7・D20）。確認の画面と確定が**同じもの**を作る
 * （確定ではファイルを読み直してもう一度作る。画面が送ってきた hidden を信用しない）。
 *
 * - 受付簿の段ごとに、部門（シートの記号 → 部門の管理の部門）・ＮＯ・日付（PaperDate）・件名・申請者を確かめ、決裁No を組み立てる
 * - **エラーの行は取り込まない**（ほかの行は取り込む。D20）: 部門の管理に無い部門（シートごとにまとめて 1 つ）・ＮＯ が無いか数でない・
 *   日付が無い・読めない・年度と合わない・今日より先・件名が無い・長すぎる・システムの申請がすでに同じ番号を持つ・同じファイルの中で同じ番号が 2 回
 * - **すでにある番号は中身を変えない**（D6。手で直したものを消さない）。Excel と中身が違えば注意を出す（直すなら 1 件ずつ）。
 *   金額（金額・工事原価・粗利益）がすべて空の記録にだけ、金額の控えから補う
 * - 金額の控えの行は、正しく読めた受付簿の行（**すべてのシート**。08 年度の「土地・建物契約・金額」は Ｊ と Ｍ の両方に当たる）に、
 *   日付と件名（空白・全角半角の違いを無視）で結び付ける。1 つに決まらない行（当たる行が 0・2 つ以上・2 つの控えが同じ行に当たる）は
 *   結び付けずに、確認の画面で選んでもらう（`$links`。確定でも同じ形で確かめ直す）。契約金額・工事原価・粗利益がどれも空の控えの行は
 *   結び付けない（金額を書く前の行。結び付けると「金額を補った」と数え、あとで本当の金額を補えなくなる）
 * - 次の番号（D7）: 今年度の部門は、足すＮＯ のいちばん大きいもの＋1 まで進む（戻さない。進めるのは PaperImporter）。確認の画面に出す
 */
final class PaperImportAnalysis
{
    /** 件名・申請者の長さの上限（申請の件名と同じ 100 文字・申請者は台帳の姓の文字） */
    public const SUBJECT_MAX = 100;

    public const APPLICANT_MAX = 50;

    /** ＮＯ の上限（関連する決裁No の形が 5 桁まで。RelatedNumbers::PATTERN） */
    public const SEQ_MAX = 99999;

    /** 金額の上限（申請の明細表と同じ 12 桁。マイナスも） */
    public const AMOUNT_MAX = 999_999_999_999;

    /** 結び付けの選択の「結び付けない」 */
    public const NO_LINK = 'none';

    /**
     * @param array<string, string> $links 金額の控えの行の鍵（`シートの番号:行`）=> 結び付ける決裁No か NO_LINK。送られていない行は自動で結び付ける
     * @return array{
     *     sheets: list<array{title: string, kind: string, code: ?string, department: ?string, rows: int, added: int, existing: int, errors: int, linked: int}>,
     *     records: list<array{number: string, department_id: int, department: string, fiscal_year: int, seq: int, decided_on: string, applicant_name: ?string, subject: string, amount: ?int, cost: ?int, profit: ?int, where: string}>,
     *     existing: list<array{number: string, id: int, where: string, fill: ?array{amount: ?int, cost: ?int, profit: ?int}}>,
     *     rowErrors: list<array{where: string, message: string}>,
     *     warnings: list<array{where: string, message: string}>,
     *     amounts: list<array{key: string, where: string, date: string, applicant: string, subject: string, amount: ?int, cost: ?int, profit: ?int, linked: ?string, auto: bool, empty: bool, candidates: list<array{number: string, label: string}>}>,
     *     linkErrors: list<string>,
     *     nextNumbers: list<array{department: string, before: string, after: string}>,
     *     addCount: int,
     *     fillCount: int,
     * }
     *
     * @throws PaperImportException ファイルとして読めない・行が多すぎる
     */
    public static function analyze(string $path, int $fiscalYear, array $links = []): array
    {
        $file        = PaperLedgerFile::read($path);
        $departments = ApprovalDepartment::with('company')->get()->keyBy('code');
        $rowErrors   = $warnings = [];

        // 1. 受付簿の段（部門の無いシートはまとめて 1 つのエラー）
        $valid    = [];
        $unknown  = [];
        foreach ($file['register'] as $row) {
            $department = $departments->get($row['code']);
            if ($department === null) {
                $unknown[$row['title']] = ($unknown[$row['title']] ?? 0) + 1;
                continue;
            }
            $checked = self::checkRow($row, $department, $fiscalYear);
            if (isset($checked['error'])) {
                $rowErrors[] = ['where' => self::where($row), 'message' => $checked['error'], 'sheet' => $row['sheet']];
                continue;
            }
            $valid[] = $checked;
        }
        foreach ($unknown as $title => $count) {
            $code        = PaperLedgerFile::codeOf($title);
            $rowErrors[] = ['where' => $title, 'message' => "部門のアルファベット {$code} の部門が部門の管理にありません（{$count} 件を取り込みません。部門を登録してから取り込み直してください）", 'sheet' => null];
        }

        // 2. 同じファイルの中の同じ番号（どちらも取り込まない）・システムの申請の番号
        $valid = self::dropTwins($valid, $rowErrors);
        $taken = ApprovalRequest::whereIn('number', array_column($valid, 'number'))->pluck('number')->flip();
        $valid = array_values(array_filter($valid, function (array $r) use ($taken, &$rowErrors): bool {
            if ($taken->has($r['number'])) {
                $rowErrors[] = ['where' => $r['where'], 'message' => "{$r['number']} はシステムの申請がすでに使っています", 'sheet' => $r['sheet']];

                return false;
            }

            return true;
        }));

        // 確認の画面で見やすいように、シートの順・ＮＯ の順に並べる（読む順は上の行から左右）
        usort($valid, fn (array $a, array $b) => [$a['sheet'], $a['seq']] <=> [$b['sheet'], $b['seq']]);

        // 3. すでにある番号（中身は変えない。違えば注意。D6）
        $existing = ApprovalPaperRecord::whereIn('number', array_column($valid, 'number'))->get()->keyBy('number');
        foreach ($valid as $r) {
            $old = $existing->get($r['number']);
            if ($old !== null && ($differs = self::differences($old, $r)) !== []) {
                $warnings[] = ['where' => $r['where'], 'message' => "{$r['number']} はすでにあります。Excel と" . implode('・', $differs) . 'が違いますが、変えません（直すなら 1 件ずつ直してください）'];
            }
        }

        // 4. 金額の控えの結び付け
        [$amounts, $linkErrors, $fills] = self::linkAmounts($file['amounts'], $valid, $links, $rowErrors);

        // 5. 足す記録・金額を補う記録
        $records = $fillsOfExisting = [];
        foreach ($valid as $r) {
            $fill = $fills[$r['number']] ?? null;
            $old  = $existing->get($r['number']);
            if ($old === null) {
                $records[] = array_merge(self::publicRecord($r), $fill ?? ['amount' => null, 'cost' => null, 'profit' => null]);
                continue;
            }
            if ($fill !== null && $old->amount === null && $old->cost === null && $old->profit === null) {
                $fillsOfExisting[] = ['number' => $r['number'], 'id' => $old->id, 'where' => $r['where'], 'fill' => $fill];
            } else {
                if ($fill !== null) {
                    $warnings[] = ['where' => $r['where'], 'message' => "{$r['number']} は金額がすでにあるので、金額の控えの金額を入れません"];
                }
                $fillsOfExisting[] = ['number' => $r['number'], 'id' => $old->id, 'where' => $r['where'], 'fill' => null];
            }
        }

        return [
            'sheets'      => self::sheetSummary($file, $departments, $valid, $existing, $amounts, $rowErrors),
            'records'     => $records,
            'existing'    => $fillsOfExisting,
            'rowErrors'   => array_map(fn (array $e) => ['where' => $e['where'], 'message' => $e['message']], $rowErrors),
            'warnings'    => $warnings,
            'amounts'     => $amounts,
            'linkErrors'  => $linkErrors,
            'nextNumbers' => self::nextNumbers($records, $departments, $fiscalYear),
            'addCount'    => count($records),
            'fillCount'   => count(array_filter($fillsOfExisting, fn (array $e) => $e['fill'] !== null)),
        ];
    }

    /**
     * 受付簿の 1 段を確かめる
     *
     * @return array{error: string}|array{number: string, department_id: int, department: string, fiscal_year: int, seq: int, decided_on: string, applicant_name: ?string, subject: string, where: string, sheet: int, dateKey: string, subjectKey: string}
     */
    private static function checkRow(array $row, ApprovalDepartment $department, int $fiscalYear): array
    {
        if ($row['no'] === null) {
            return ['error' => 'ＮＯ が空です'];
        }
        $seq = self::seqOf($row['no']);
        if ($seq === null) {
            return ['error' => "ＮＯ「{$row['no']}」が 1〜" . number_format(self::SEQ_MAX) . ' の数ではありません'];
        }
        if ($row['date'] === null) {
            return ['error' => '日付が空です（件名などが書いてあるのに日付が無い行は取り込みません）'];
        }
        $date = PaperDate::read($row['date'], $fiscalYear, $department->company->fiscal_start_month);
        if ($date['error'] !== null) {
            return ['error' => $date['error']];
        }
        // 今日より先の決裁日は紙の記録としてありえない（年度の和暦と暦の和暦の取り違え・まだ始まっていない会社の年度。日付で比べる）
        if ($date['date']->format('Y-m-d') > JapanTime::today()->format('Y-m-d')) {
            return ['error' => "日付「{$row['date']}」が今日より先の日付（{$date['date']->format('Y/m/d')}）になります"];
        }
        if ($row['subject'] === null) {
            return ['error' => '件名が空です'];
        }
        if (mb_strlen($row['subject']) > self::SUBJECT_MAX) {
            return ['error' => '件名が ' . self::SUBJECT_MAX . ' 文字を超えています'];
        }
        if ($row['applicant'] !== null && mb_strlen($row['applicant']) > self::APPLICANT_MAX) {
            return ['error' => '申請者が ' . self::APPLICANT_MAX . ' 文字を超えています'];
        }

        return [
            'number'         => ApprovalNumber::paperNumber($department, $fiscalYear, $seq),
            'department_id'  => $department->id,
            'department'     => $department->name,
            'fiscal_year'    => $fiscalYear,
            'seq'            => $seq,
            'decided_on'     => $date['date']->format('Y-m-d'),
            'applicant_name' => $row['applicant'],
            'subject'        => $row['subject'],
            'where'          => self::where($row),
            'sheet'          => $row['sheet'],
            'dateKey'        => (string) PaperDate::key($row['date']),
            'subjectKey'     => self::matchKey($row['subject']),
        ];
    }

    /** 同じ番号が 2 回以上ある行はどれも取り込まない（どちらが正しいか分からない） */
    private static function dropTwins(array $valid, array &$rowErrors): array
    {
        $groups = [];
        foreach ($valid as $r) {
            $groups[$r['number']][] = $r;
        }

        $out = [];
        foreach ($groups as $number => $rows) {
            if (count($rows) === 1) {
                $out[] = $rows[0];
                continue;
            }
            foreach ($rows as $r) {
                $others      = implode('・', array_map(fn (array $o) => $o['where'], array_filter($rows, fn (array $o) => $o['where'] !== $r['where'])));
                $rowErrors[] = ['where' => $r['where'], 'message' => "同じファイルの中に {$number} がもう 1 つあります（{$others}）", 'sheet' => $r['sheet']];
            }
        }

        return $out;
    }

    /** すでにある記録と Excel の違い（項目の名前） */
    private static function differences(ApprovalPaperRecord $old, array $r): array
    {
        $differs = [];
        if ($old->decided_on->format('Y-m-d') !== $r['decided_on']) {
            $differs[] = '決裁日';
        }
        if ($old->subject !== $r['subject']) {
            $differs[] = '件名';
        }
        if ((string) $old->applicant_name !== (string) $r['applicant_name']) {
            $differs[] = '申請者';
        }

        return $differs;
    }

    /**
     * 金額の控えの行を受付簿の行に結び付ける
     *
     * @param list<array> $rows   金額の控えの行（PaperLedgerFile）
     * @param list<array> $valid  取り込める受付簿の行（足すものと、すでにあるもの）
     * @param array<string, string> $links 画面で選んだ結び付け
     * @return array{0: list<array>, 1: list<string>, 2: array<string, array{amount: ?int, cost: ?int, profit: ?int}>} 画面に出す行・結び付けの誤り・決裁No ごとの金額
     */
    private static function linkAmounts(array $rows, array $valid, array $links, array &$rowErrors): array
    {
        $targets = [];
        foreach ($valid as $r) {
            $targets[$r['number']] = $r;
        }

        // 金額を確かめ、自動の結び付けの先を探す
        $usable = [];
        foreach ($rows as $row) {
            $where  = self::amountWhere($row);
            $values = [];
            foreach (['amount' => '契約金額', 'cost' => '工事原価', 'profit' => '粗利益'] as $key => $label) {
                $value = self::amountOf($row[$key]);
                if ($value === false) {
                    $rowErrors[] = ['where' => $where, 'message' => "金額の控えの{$label}「{$row[$key]}」が 12 桁までの数ではありません（この行の金額は入れません）", 'sheet' => $row['sheet']];
                    continue 2;
                }
                $values[$key] = $value;
            }
            $dateKey    = PaperDate::key($row['date']);
            $subjectKey = self::matchKey($row['subject']);
            // 金額がどれも空の行は結び付けない（当たる行を探さない＝ほかの控えの自動の結び付けも邪魔しない）
            $empty      = $values['amount'] === null && $values['cost'] === null && $values['profit'] === null;
            $matches    = $empty ? [] : array_keys(array_filter($targets, fn (array $t) => $t['dateKey'] === $dateKey && $t['subjectKey'] === $subjectKey));
            $usable[]   = ['key' => "{$row['sheet']}:{$row['line']}", 'where' => $where, 'row' => $row, 'values' => $values, 'matches' => $matches, 'dateKey' => $dateKey, 'empty' => $empty];
        }

        // 自動で結び付くのは、当たる行がちょうど 1 つで、ほかの控えがその行に当たっていないものだけ
        $claims = array_count_values(array_merge(...array_map(fn (array $u) => count($u['matches']) === 1 ? $u['matches'] : [], $usable) ?: [[]]));

        $out = $linkErrors = $fills = $chosen = [];
        foreach ($usable as $u) {
            $auto   = count($u['matches']) === 1 && $claims[$u['matches'][0]] === 1;
            $choice = $u['empty'] ? null : ($links[$u['key']] ?? null);
            $linked = null;
            if (is_string($choice)) {
                if ($choice === self::NO_LINK) {
                    $linked = null;
                } elseif (isset($targets[$choice])) {
                    $linked = $choice;
                } else {
                    $linkErrors[] = "{$u['where']} の結び付け先 {$choice} は、このファイルで取り込める記録にありません。選び直してください";
                }
                $auto = false;
            } elseif ($auto) {
                $linked = $u['matches'][0];
            }

            if ($linked !== null) {
                $chosen[$linked][] = $u['where'];
                $fills[$linked]    = $u['values'];
            }

            $out[] = [
                'key'        => $u['key'],
                'where'      => $u['where'],
                'date'       => (string) $u['row']['date'],
                'applicant'  => $u['row']['applicant'],
                'subject'    => $u['row']['subject'],
                'linked'     => $linked,
                'auto'       => $auto,
                'empty'      => $u['empty'],
                'candidates' => $auto || $u['empty'] ? [] : self::candidates($targets, $u['dateKey']),
            ] + $u['values'];
        }

        foreach ($chosen as $number => $wheres) {
            if (count($wheres) > 1) {
                $linkErrors[] = '金額の控えの ' . implode('・', $wheres) . " が同じ {$number} に結び付いています。どちらかを「結び付けない」にしてください";
                unset($fills[$number]);
            }
        }

        return [$out, $linkErrors, $fills];
    }

    /**
     * 結び付け先の選択肢（同じ日付の記録を先に。あとは決裁No の順）
     *
     * @return list<array{number: string, label: string}>
     */
    private static function candidates(array $targets, ?string $dateKey): array
    {
        $list = array_values($targets);
        usort($list, fn (array $a, array $b) => [$a['dateKey'] !== $dateKey, $a['sheet'], $a['seq']] <=> [$b['dateKey'] !== $dateKey, $b['sheet'], $b['seq']]);

        return array_map(fn (array $t) => [
            'number' => $t['number'],
            'label'  => "{$t['number']}　" . str_replace('-', '/', $t['decided_on']) . "　{$t['applicant_name']}　{$t['subject']}" . ($t['dateKey'] === $dateKey ? '（同じ日付）' : ''),
        ], $list);
    }

    /**
     * 次の番号（D7）。今年度の部門だけ・足すＮＯ のいちばん大きいもの＋1（今の次の番号より小さければ変えない）。
     * 過去の年度の紙の番号は採番に関わらない（§5.11）
     *
     * @return list<array{department: string, before: string, after: string}>
     */
    private static function nextNumbers(array $records, Collection $departments, int $fiscalYear): array
    {
        $max = [];
        foreach ($records as $r) {
            $max[$r['department_id']] = max($max[$r['department_id']] ?? 0, $r['seq']);
        }

        $out = [];
        foreach ($departments->sortBy(['company_id', 'sort_order', 'id']) as $department) {
            if (! isset($max[$department->id])) {
                continue;
            }
            $state = ApprovalNumber::currentState($department);
            if ($state['fiscal_year'] !== $fiscalYear) {
                continue;
            }
            $out[] = [
                'department' => $department->name,
                'before'     => ApprovalNumber::format($state['era'], $department->code, $state['next']),
                'after'      => ApprovalNumber::format($state['era'], $department->code, max($state['next'], $max[$department->id] + 1)),
            ];
        }

        return $out;
    }

    /**
     * シートごとの件数（受付簿は読んだ段・足す・すでにある・エラー。金額の控えは読んだ行・結び付いた行。読まないシートも並べる）
     *
     * @param Collection<string, ApprovalPaperRecord> $existing すでにある記録（決裁No => 記録）
     * @return list<array{title: string, kind: string, code: ?string, department: ?string, rows: int, added: int, existing: int, errors: int, linked: int}>
     */
    private static function sheetSummary(array $file, Collection $departments, array $valid, Collection $existing, array $amounts, array $rowErrors): array
    {
        $rows     = array_count_values(array_merge(array_column($file['register'], 'sheet'), array_column($file['amounts'], 'sheet')));
        $added    = array_count_values(array_column(array_filter($valid, fn (array $r) => ! $existing->has($r['number'])), 'sheet'));
        $kept     = array_count_values(array_column(array_filter($valid, fn (array $r) => $existing->has($r['number'])), 'sheet'));
        $errors   = array_count_values(array_column(array_filter($rowErrors, fn (array $e) => $e['sheet'] !== null), 'sheet'));
        $linked   = array_count_values(array_map(fn (array $a) => (int) explode(':', $a['key'])[0], array_filter($amounts, fn (array $a) => $a['linked'] !== null)));

        return array_map(fn (array $s) => [
            'title'      => $s['title'],
            'kind'       => $s['kind'],
            'code'       => $s['code'],
            'department' => $s['code'] === null ? null : $departments->get($s['code'])?->name,
            'rows'       => $rows[$s['index']] ?? 0,
            'added'      => $added[$s['index']] ?? 0,
            'existing'   => $kept[$s['index']] ?? 0,
            // 部門の管理に無い部門のシートは、読んだ段をすべて取り込まない（エラーはシートごとに 1 つにまとめて出す）
            'errors'     => $s['kind'] === PaperLedgerFile::REGISTER && ! $departments->has($s['code']) ? ($rows[$s['index']] ?? 0) : ($errors[$s['index']] ?? 0),
            'linked'     => $linked[$s['index']] ?? 0,
        ], $file['sheets']);
    }

    /** 画面に出す・保存する形（結び付けの鍵などを外す） */
    private static function publicRecord(array $r): array
    {
        return array_intersect_key($r, array_flip(['number', 'department_id', 'department', 'fiscal_year', 'seq', 'decided_on', 'applicant_name', 'subject', 'where']));
    }

    /** ＮＯ（1〜99999 の整数。数のセルは小数の無いものだけ） */
    private static function seqOf(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = floor($value) === $value ? (int) $value : null;
        } elseif (is_string($value)) {
            $value = PaperLedgerFile::isNumber($value) ? (int) mb_convert_kana($value, 'n') : null;
        }

        return is_int($value) && $value >= 1 && $value <= self::SEQ_MAX ? $value : null;
    }

    /** 金額（空は null・整数で 12 桁まで・文字の数も読む。読めなければ false） */
    private static function amountOf(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) <= self::AMOUNT_MAX ? (int) $value : false;
        } elseif (is_string($value)) {
            $text  = FormInput::digits($value, FormInput::YEN);
            $value = is_string($text) && preg_match('/\A-?\d{1,12}\z/', $text) ? (int) $text : false;
        }

        return is_int($value) && abs($value) <= self::AMOUNT_MAX ? $value : false;
    }

    /** 結び付けで比べる件名（空白をすべて落とし、英数字・記号を半角に・半角カナを全角に） */
    private static function matchKey(string $subject): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', mb_convert_kana($subject, 'asKV')) ?? $subject;
    }

    /** 受付簿の段の場所（「Ｊ・住宅 5 行目・左（ＮＯ 1）」） */
    private static function where(array $row): string
    {
        return "{$row['title']} {$row['line']} 行目・{$row['side']}" . ($row['no'] === null ? '' : "（ＮＯ {$row['no']}）");
    }

    /** 金額の控えの行の場所（「土地・建物契約・金額 5 行目（ＮＯ 1）」） */
    private static function amountWhere(array $row): string
    {
        return "{$row['title']} {$row['line']} 行目" . ($row['no'] === null ? '' : "（ＮＯ {$row['no']}）");
    }
}
