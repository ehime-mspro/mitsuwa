<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CSV 取込の確定で、データベースのエラーを起こす（テナント・賃貸マンションの取込のテストで共用。docs/RULES.md Bug #89 の追記）。
 *
 * 起こす例外は、本番の MySQL が列に入らない値を断ったときと同じ形（SQL の文と入力値を含む QueryException）。
 * 断る SQL は実行する前に止める（`DB::beforeExecuting`。その書き込みは DB に届かない）。
 */
trait FailsImportWrites
{
    /** 確定でデータベースのエラーが起きたときの案内（$row は書き込んでいた行の番号。null は書き込みを始める前） */
    private static function importDatabaseError(?int $row): string
    {
        $where = $row === null ? '書き込みを始める前に' : "行{$row} まで進んだところで";

        return "取り込めませんでした（{$where}データベースのエラーが起きました）。1件も登録していません。"
            . 'CSVを確かめて、アップロードし直してください。理由はシステムの記録（laravel.log）に残しています。';
    }

    /**
     * $pattern（SQL の文に当てる正規表現）に当たる $nth 番目の SQL を、実行する前に断る（1 回だけ）。
     *
     * 例: `failQuery('/^insert into "properties"/i', 2)` は 2 件目の物件の INSERT を断る。
     */
    private function failQuery(string $pattern, int $nth = 1): void
    {
        $seen = 0;
        DB::beforeExecuting(function (string $sql, array $bindings) use ($pattern, $nth, &$seen): void {
            if (preg_match($pattern, $sql) !== 1 || ++$seen !== $nth) {
                return;
            }

            throw new QueryException(
                'mysql',
                $sql,
                $bindings,
                new \PDOException('SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column')
            );
        });
    }
}
