<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
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
     * ⚠ その年度に使った番号より小さい数は断る（D9。番号が重なるのを防ぐ）。
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

            $row->update(['next_number' => $next]);
        });
    }

    /** 番号を付けた申請がある部門か（会社とアルファベットを変えられない。D8） */
    public static function departmentHasNumbers(ApprovalDepartment $department): bool
    {
        return ApprovalRequest::where('number_department_id', $department->id)->exists();
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
