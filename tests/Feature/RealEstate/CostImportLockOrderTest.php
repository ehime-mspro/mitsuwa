<?php

namespace Tests\Feature\RealEstate;

use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use App\Support\RealEstateCostImportService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/**
 * 試算表 Excel 取込（RealEstateCostImportService）は、原価の行に書く前に親（案件）の行をロックする。
 *
 * 原価の表の保存（RealEstateCostGrid::save）は「親 → 原価の行」の順にロックする。取込が親をロックせずに
 * 「原価の行を消す → 行を足す」と進むと、足すときの外部キーの確かめが親を共有ロックするので逆向きの輪になり、
 * 上書きの取込と表の保存が重なるとデッドロック（1213）で表の保存が落ちた（2026-11-17 に使い捨ての MySQL 8.4 で実測）。
 *
 * ⚠ テストの SQLite は FOR UPDATE を書かない（SQLiteGrammar はロックを無視する）ので、ロックそのものは字句で、
 *   順は流れた SQL で見る（Bug #102 と同じ見方）。
 */
class CostImportLockOrderTest extends RealEstateScreenTestCase
{
    public static function importers(): array
    {
        return [
            '仕入れ案件・上書き' => ['procurement', 'overwrite'],
            '仕入れ案件・追加'   => ['procurement', 'append'],
            '分譲地・上書き'     => ['project', 'overwrite'],
            '分譲地・追加'       => ['project', 'append'],
        ];
    }

    #[DataProvider('importers')]
    public function test_the_import_locks_the_owner_before_writing_costs(string $kind, string $mode): void
    {
        $other = ReCostItem::create(['name' => '仲介手数料', 'sort_order' => 9]);
        if ($kind === 'procurement') {
            $owner = $this->procurement();
            ReProcurementCost::create(['procurement_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 1]);
            $ownerTable = 're_procurements';
            $costTable = 're_procurement_costs';
        } else {
            $owner = $this->project();
            ReProjectCost::create(['project_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 1]);
            $ownerTable = 're_projects';
            $costTable = 're_project_costs';
        }

        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log) {
            $log[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$log) {
            $log[] = $query->sql;
        });

        $rows = [['cost_item_id' => $other->id, 'estimated_amount' => 300000, 'actual_amount' => null, 'notes' => null]];
        $service = new RealEstateCostImportService();
        $kind === 'procurement'
            ? $service->importToProcurement($owner, $rows, $mode)
            : $service->importToProject($owner, $rows, $mode);

        $begin = array_search('BEGIN', $log, true);
        $lock = $this->firstIndex($log, '/^select \* from "' . $ownerTable . '" where "' . $ownerTable . '"\."id" = \? limit 1$/');
        $write = $this->firstIndex($log, '/^(delete from|insert into) "' . $costTable . '"/');

        $this->assertNotFalse($begin, 'トランザクションが始まっていない');
        $this->assertNotNull($lock, '取込が親の行を読んでいない: ' . json_encode($log, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($write, '原価の行に書いていない');
        $this->assertTrue($begin < $lock && $lock < $write,
            '親の行は、トランザクションの中で原価の行に書く前に読む: ' . json_encode($log, JSON_UNESCAPED_UNICODE));
    }

    public static function importMethods(): array
    {
        return ['仕入れ案件' => ['importToProcurement'], '分譲地' => ['importToProject']];
    }

    #[DataProvider('importMethods')]
    public function test_the_lock_is_for_update_and_comes_before_the_writes(string $method): void
    {
        $reflection = new ReflectionMethod(RealEstateCostImportService::class, $method);
        $lines = array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1);
        $code = '';
        foreach (token_get_all('<?php ' . implode('', $lines)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $transaction = strpos($code, 'DB::transaction(');
        $lock = strpos($code, '->lockForUpdate()');
        $writes = array_filter([strpos($code, 'deleteExceptPropertyPurchase('), strpos($code, 'Cost::create(')], fn ($p) => $p !== false);

        $this->assertNotFalse($transaction, '取込がトランザクションで包まれていない');
        $this->assertNotFalse($lock, '取込が親の行をロックしていない（表の保存と逆の順になる）');
        $this->assertCount(2, $writes, '書く所（消す・足す）を見つけられない');
        $this->assertTrue($transaction < $lock && $lock < min($writes), 'ロックはトランザクションの中で、書く前に取る');
    }

    private function firstIndex(array $log, string $pattern): ?int
    {
        foreach ($log as $i => $sql) {
            if ($sql !== 'BEGIN' && preg_match($pattern, $sql) === 1) {
                return $i;
            }
        }

        return null;
    }
}
