<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 決裁No の採番（要件 6・設計書 §5.9・計画 §0.4）。
 *
 * ⚠ **社長の判断と同じトランザクションの中で呼ぶ**（判断が断られたら番号も戻る）。`issue()` も自分で
 *   トランザクションに包む（外から呼ばれても行のロックが効くように。入れ子なら savepoint になるだけ）。
 * ⚠ 連番の行は `upsert` で用意してから `lockForUpdate()` で読む。行が無いときに 2 人が同時に作っても、
 *   一意の索引（部門・年度）で 1 行になる。
 */
final class ApprovalNumber
{
    /**
     * 次の番号を採る。
     *
     * @return array{number: string, fiscal_year: int, seq: int}
     */
    public static function issue(ApprovalDepartment $department, DateTimeInterface $decidedAt): array
    {
        return DB::transaction(function () use ($department, $decidedAt): array {
            $startMonth = $department->company->fiscal_start_month;
            $fiscalYear = ApprovalFiscalYear::ofMoment($decidedAt, $startMonth);

            $row = self::lockedRow($department, $fiscalYear);
            $seq = $row->next_number;
            $row->update(['next_number' => $seq + 1, 'last_issued' => $seq]);

            return [
                'number'      => self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth), $department->code, $seq),
                'fiscal_year' => $fiscalYear,
                'seq'         => $seq,
            ];
        });
    }

    /** `R8-J-001`（999 の次は `R8-J-1000`。要件 6.1） */
    public static function format(string $era, string $code, int $seq): string
    {
        return sprintf('%s-%s-%03d', $era, $code, $seq);
    }

    /**
     * 今年度の状態（部門の管理に出す）。
     *
     * @return array{fiscal_year: int, era: string, next: int, last_issued: int}
     */
    public static function currentState(ApprovalDepartment $department): array
    {
        $startMonth = $department->company->fiscal_start_month;
        $fiscalYear = ApprovalFiscalYear::current($startMonth);
        $row        = ApprovalNumberSequence::where('department_id', $department->id)->where('fiscal_year', $fiscalYear)->first();

        return [
            'fiscal_year' => $fiscalYear,
            'era'         => ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth),
            'next'        => $row?->next_number ?? 1,
            'last_issued' => $row?->last_issued ?? 0,
        ];
    }

    /**
     * 今年度の「次に付く番号」を設定する（要件 6.4）。
     *
     * ⚠ その年度に使った番号より小さい数は断る（D9。番号が重なるのを防ぐ）。取り込んだ今年度の紙の決裁の番号以下も断る（段階5 §5.11）。
     *
     * @throws InvalidArgumentException 使った番号以下のとき（メッセージはそのまま画面に出せる）
     */
    public static function setNext(ApprovalDepartment $department, int $next): void
    {
        DB::transaction(function () use ($department, $next): void {
            $fiscalYear = ApprovalFiscalYear::current($department->company->fiscal_start_month);
            $row        = self::lockedRow($department, $fiscalYear);

            if ($next <= $row->last_issued) {
                $used = self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $row->last_issued);
                throw new InvalidArgumentException("今年度はすでに {$used} まで使っています。" . ($row->last_issued + 1) . ' 以上を入れてください。');
            }

            // ⚠ ロック付きの読み（連番の行のロックを待つあいだに取り込みが commit した紙の番号も見る。reservePaperNumbers と同じ理由）
            $paper = self::lastPaperSeq($department, $fiscalYear, lock: true);
            if ($next <= $paper) {
                $used = self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $paper);
                throw new InvalidArgumentException("今年度は紙の決裁で {$used} まで使っています。" . ($paper + 1) . ' 以上を入れてください。');
            }

            $row->update(['next_number' => $next]);
        });
    }

    /**
     * 今年度の紙の決裁の番号を足したとき（取り込み・1 件の登録・番号の直し）、その部門の次の番号を「紙の番号＋1」まで進める（段階5 D7・§5.11）。
     * **戻さない**（次の番号のほうが大きければそのまま）。過去の年度の紙の番号は採番に関わらない（何もしない）。
     *
     * ⚠ 連番の行をロックする（社長の判断の採番と重ならない）。紙の決裁を保存するトランザクションの中で、保存の前に呼ぶ
     *   （保存が断られたら番号も戻る。ロックのあとでシステムの申請の番号を確かめれば、確認と確定のあいだの採番にも気づける）
     *
     * @return int|null 進めたあとの次の番号（今年度でなければ null）
     */
    public static function advancePast(ApprovalDepartment $department, int $fiscalYear, int $seq): ?int
    {
        return DB::transaction(function () use ($department, $fiscalYear, $seq): ?int {
            if ($fiscalYear !== ApprovalFiscalYear::current($department->company->fiscal_start_month)) {
                return null;
            }

            $row = self::lockedRow($department, $fiscalYear);
            if ($row->next_number <= $seq) {
                $row->update(['next_number' => $seq + 1]);
            }

            return $row->next_number;
        });
    }

    /**
     * 紙の決裁の番号を足す前の確かめ（取り込みの確定と 1 件ずつの登録・直しが、保存と同じトランザクションの中で**保存の前に**呼ぶ。段階5 D7・§5.11）
     *
     * 1. 部門の id の順に、今年度の部門の次の番号を進める（advancePast。連番の行をロックする＝社長の判断の採番と順番に並ぶ。戻さない）
     * 2. **ロックのあとで**、システムの申請がすでに使っている番号を**ロック付きの読み**（共有ロック）で確かめる
     *
     * ⚠ 2 を普通の読みにしない。MySQL の REPEATABLE READ では、トランザクションで最初に普通に読んだ時点の中身を読み続けるので、
     *   連番の行のロックを待つあいだに社長の判断が付けた番号が見えない（2026-10-13 の点検で、使い捨ての MySQL の 2 本の接続で再現した。
     *   SQLite のテストでは見えない。Workflow の「ロックより前に読まない」と同じ罠）。`number` は一意の索引なので、共有ロックは当たる行と隙間だけ
     *
     * @param array<int, int> $maxSeqs 部門の id => 足すいちばん大きいＮＯ
     * @param list<string> $numbers 足す決裁No
     * @return array{next: array<int, int>, taken: list<string>} 進めたあとの次の番号（部門の id => 番号。今年度の部門だけ）と、システムの申請がすでに使っている番号
     */
    public static function reservePaperNumbers(array $maxSeqs, int $fiscalYear, array $numbers): array
    {
        ksort($maxSeqs);
        $departments = ApprovalDepartment::with('company')->whereKey(array_keys($maxSeqs))->get()->keyBy('id');

        $next = [];
        foreach ($maxSeqs as $departmentId => $seq) {
            $after = self::advancePast($departments[$departmentId], $fiscalYear, $seq);
            if ($after !== null) {
                $next[$departmentId] = $after;
            }
        }

        $taken = $numbers === [] ? [] : ApprovalRequest::whereIn('number', $numbers)->sharedLock()->orderBy('number')->pluck('number')->all();

        return ['next' => $next, 'taken' => $taken];
    }

    /** その年度の紙の決裁のいちばん大きいＮＯ（無ければ 0。部門の管理の案内と開始番号の検査。検査はロック付きの読み） */
    public static function lastPaperSeq(ApprovalDepartment $department, int $fiscalYear, bool $lock = false): int
    {
        return (int) ApprovalPaperRecord::where('department_id', $department->id)->where('fiscal_year', $fiscalYear)
            ->when($lock, fn ($q) => $q->sharedLock())
            ->max('seq');
    }

    /** 番号を付けた申請がある部門か（会社とアルファベットを変えられない。D8） */
    public static function departmentHasNumbers(ApprovalDepartment $department): bool
    {
        return ApprovalRequest::where('number_department_id', $department->id)->exists();
    }

    /**
     * 取り込んだ紙の決裁がある部門か（紙の決裁No にもアルファベットと年度の和暦が入るので、会社とアルファベットを変えられない。段階5 §5.11）。
     * 申請の番号（departmentHasNumbers）とは別に聞く（部門の管理の断りの文を分ける）
     */
    public static function departmentHasPaperNumbers(ApprovalDepartment $department): bool
    {
        return ApprovalPaperRecord::where('department_id', $department->id)->exists();
    }

    /** 紙の決裁の決裁No（年度・部門・ＮＯから。和暦は部門の会社の期で決める。R8-J-021。要件 11.2） */
    public static function paperNumber(ApprovalDepartment $department, int $fiscalYear, int $seq): string
    {
        return self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $seq);
    }

    private static function lockedRow(ApprovalDepartment $department, int $fiscalYear): ApprovalNumberSequence
    {
        // ⚠ 時計は 1 回だけ読む（ClockReadScanTest はファイルごとの件数を見る）
        $now = now();

        // ⚠ insertOrIgnore にしない。行がすでにあるとき、MySQL の INSERT IGNORE は重複した行（一意の索引）に**共有ロック**を取る。
        //   READ COMMITTED では 2 つのトランザクションが共有ロックを持ったまま次の FOR UPDATE（排他ロック）を待ち合い、
        //   デッドロック（1213）で片方が巻き戻される（社長の判断と開始番号の設定が重なったときなど。その人の画面は 500）。
        //   既定の REPEATABLE READ では 2 本目の INSERT IGNORE が主キーの末尾のすき間のロックで先に待つので起きにくいが、
        //   分離レベルに頼らない。upsert（INSERT … ON DUPLICATE KEY UPDATE）は重複した行に**排他ロック**を取るので、後の方は待つだけ。
        //   （2026-09-27 に MySQL 8.4.8 で実測。SQLite のテストではロックの違いは見えない）
        DB::table('approval_number_sequences')->upsert([
            'department_id' => $department->id,
            'fiscal_year'   => $fiscalYear,
            'next_number'   => 1,
            'last_issued'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], ['department_id', 'fiscal_year'], ['updated_at']);

        return ApprovalNumberSequence::where('department_id', $department->id)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
