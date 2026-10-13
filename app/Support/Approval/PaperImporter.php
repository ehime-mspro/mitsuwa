<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 確かめた取り込みの中身（PaperImportAnalysis）を保存する（段階5 設計書 §5.9・D6・D7・D9）。1 つのトランザクション。
 *
 * - 今年度の部門は、連番の行をロックして次の番号を進める（D7。戻さない）。**ロックのあとで**システムの申請の番号をロック付きの読みで
 *   もう一度確かめる（確認の画面を出してから確定までのあいだに、社長の判断で同じ番号が付いていたら断る。ApprovalNumber::reservePaperNumbers）
 * - 取り込み 1 回分（approval_paper_imports）を作り、足した記録に結び付ける（取り消しの単位。D9）
 * - すでにある記録は、金額がすべて空のときだけ金額の控えから補う（D6。確定の瞬間にも空かを確かめる＝手で入れた金額を上書きしない）
 * - 設定の記録に残す（何を・何件・次の番号）
 *
 * ⚠ 紙どうしの番号の重なり（ほかの管理者が同時に同じ番号を登録した）は一意の索引で止まる（呼ぶ側が UniqueConstraintViolationException を断る）
 */
final class PaperImporter
{
    /**
     * @param array{records: list<array>, existing: list<array>} $analysis
     * @return array{import: ApprovalPaperImport, next: list<string>} 取り込み 1 回分と、実際に進めた次の番号（「住宅事業部 R8-J-035」）
     * @throws PaperImportException 確認のあとでシステムの申請が同じ番号を使った
     */
    public static function apply(array $analysis, int $fiscalYear, string $fileName, User $user): array
    {
        return DB::transaction(function () use ($analysis, $fiscalYear, $fileName, $user): array {
            // 1. 次の番号を進め、ロックのあとでシステムの申請の番号をロック付きの読みで確かめる（ApprovalNumber::reservePaperNumbers）
            $max = [];
            foreach ($analysis['records'] as $r) {
                $max[$r['department_id']] = max($max[$r['department_id']] ?? 0, $r['seq']);
            }
            $reserved = ApprovalNumber::reservePaperNumbers($max, $fiscalYear, array_column($analysis['records'], 'number'));
            if ($reserved['taken'] !== []) {
                throw new PaperImportException('確認の画面を出したあとで、システムの申請が ' . implode('・', $reserved['taken']) . ' を使いました。もう一度アップロードして確かめてください。');
            }
            $departments = ApprovalDepartment::with('company')->whereKey(array_keys($reserved['next']))->get()->keyBy('id');
            $next        = $labels = [];
            foreach ($reserved['next'] as $departmentId => $after) {
                $department                = $departments[$departmentId];
                $next[$department->code]   = $after;
                $labels[]                  = "{$department->name} " . ApprovalNumber::paperNumber($department, $fiscalYear, $after);
            }

            // 3. 取り込み 1 回分と、足す記録
            $import = ApprovalPaperImport::create([
                'file_name'   => $fileName,
                'fiscal_year' => $fiscalYear,
                'added_count' => count($analysis['records']),
                'imported_by' => $user->id,
            ]);
            foreach ($analysis['records'] as $r) {
                ApprovalPaperRecord::create(array_intersect_key($r, array_flip([
                    'department_id', 'fiscal_year', 'seq', 'number', 'decided_on', 'applicant_name', 'subject', 'amount', 'cost', 'profit',
                ])) + ['import_id' => $import->id, 'created_by' => $user->id]);
            }

            // 4. すでにある記録の金額を補う（いまも金額がすべて空のものだけ）
            $filled = 0;
            foreach ($analysis['existing'] as $e) {
                if ($e['fill'] !== null) {
                    $filled += ApprovalPaperRecord::whereKey($e['id'])->whereNull('amount')->whereNull('cost')->whereNull('profit')->update($e['fill']);
                }
            }
            $import->update(['filled_count' => $filled]);

            SettingLogger::record('paper.imported', 'approval_paper_import', $import->id, [], [
                'file_name'    => $fileName,
                'fiscal_year'  => $fiscalYear,
                'added'        => $import->added_count,
                'filled'       => $filled,
                'next_numbers' => $next,
            ]);

            return ['import' => $import, 'next' => $labels];
        });
    }
}
