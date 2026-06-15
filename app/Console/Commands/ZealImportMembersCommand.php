<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Support\Settings;
use App\Support\Zeal\HacomonoCsvReader;
use App\Support\Zeal\HacomonoMemberMapper;
use App\Support\Zeal\MappedMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZealImportMembersCommand extends Command
{
    protected $signature = 'zeal:import-members {path : hacomono形式CSVのパス}
        {--commit : 実際にDBへ投入（未指定はdry-run）}
        {--actor=m-saiki@mitsuwat.co.jp : 登録者(created_by)のメールアドレス}';

    protected $description = 'hacomono形式CSVからZEAL会員を移行（既定はdry-run）';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("ファイルが見つかりません: {$path}");
            return self::FAILURE;
        }

        $actor = User::where('email', $this->option('actor'))->first();
        if (!$actor) {
            $this->error("登録者ユーザーが見つかりません: {$this->option('actor')}");
            return self::FAILURE;
        }

        $rows = HacomonoCsvReader::read($path);

        $planIdMap    = ZealPlan::pluck('id', 'name')->toArray();
        $planPriceMap = ZealPlan::pluck('regular_price_excl', 'name')->toArray();
        $storeIdMap   = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)->orderBy('display_order')->orderBy('id')->first();
        if (!$defaultStore) {
            $this->error('有効な店舗がありません');
            return self::FAILURE;
        }
        $taxRate = Settings::taxRate();

        $mapper = new HacomonoMemberMapper($planIdMap, $planPriceMap, $storeIdMap, $defaultStore->id, $taxRate);

        /** @var MappedMember[] $toImport */
        $toImport = [];
        /** @var MappedMember[] $skipped */
        $skipped = [];
        /** @var MappedMember[] $errored */
        $errored = [];

        foreach ($rows as $row) {
            if (!HacomonoMemberMapper::isInScope($row)) {
                continue;
            }
            $m = $mapper->map($row);

            // 冪等性: 同名・同入会日が既存ならスキップ（再実行・誤再投入に耐える）。
            // 注: 同姓同名かつ同入会日の別人は誤スキップになるが、35件の一回移行では発生しないと確認済み。
            if (!$m->hasErrors()
                && ZealMember::where('name', $m->displayName)
                    ->where('joined_on', $m->memberAttributes['joined_on'])
                    ->exists()
            ) {
                $skipped[] = $m;
                continue;
            }
            $m->hasErrors() ? $errored[] = $m : $toImport[] = $m;
        }

        $this->renderPreview($toImport, $skipped, $errored);

        if (!$this->option('commit')) {
            $this->info('dry-run（投入するには --commit を付けて再実行）');
            return self::SUCCESS;
        }

        return $this->commit($toImport, $errored, $actor->id, $taxRate);
    }

    /**
     * @param MappedMember[] $toImport
     * @param MappedMember[] $skipped
     * @param MappedMember[] $errored
     */
    private function renderPreview(array $toImport, array $skipped, array $errored): void
    {
        $kindLabel = [
            HacomonoMemberMapper::KIND_ACTIVE        => '在籍',
            HacomonoMemberMapper::KIND_WITHDRAWN     => '退会済',
            HacomonoMemberMapper::KIND_DORMANT       => '休会',
            HacomonoMemberMapper::KIND_TICKET        => 'チケット',
            HacomonoMemberMapper::KIND_INACTIVE_ZERO => '定期OFF',
        ];

        $tableRows = [];
        foreach ($toImport as $m) {
            $tableRows[] = [
                $m->sourceId,
                $m->displayName,
                $m->status,
                $kindLabel[$m->kind] ?? $m->kind,
                $m->planName ?? "（未対応:{$m->rawPlan}）",
                $m->sourceAmountIncl ?? '-',
                $m->courseListIncl ?? '-',
                $m->appliedPriceExcl ?? '-',
                $m->withdrewOn ?? $m->scheduledOn ?? '',
                implode(' / ', $m->warnings),
            ];
        }

        $this->table(
            ['元ID', '氏名', '状態', '区分', 'プラン', '元金額', '定価', '税抜', '退会(予定)', '警告'],
            $tableRows
        );

        if ($errored) {
            $this->error('--- エラー行（取込しない） ---');
            foreach ($errored as $m) {
                $this->line("  {$m->sourceId} {$m->displayName}: " . implode(' / ', $m->errors));
            }
        }
        if ($skipped) {
            $this->warn('--- スキップ（同名・同入会日が既存） ---');
            foreach ($skipped as $m) {
                $this->line("  {$m->sourceId} {$m->displayName}");
            }
        }

        $this->info(sprintf(
            '取込予定 %d 件 / スキップ %d 件 / エラー %d 件',
            count($toImport), count($skipped), count($errored)
        ));
    }

    /**
     * @param MappedMember[] $toImport
     * @param MappedMember[] $errored
     */
    private function commit(array $toImport, array $errored, int $actorId, float $taxRate): int
    {
        if ($errored) {
            $this->error('エラー行があるため中断しました。元CSVを修正して再実行してください。');
            return self::FAILURE;
        }
        if (!$this->confirm(count($toImport) . ' 件を本当に投入しますか？')) {
            $this->info('中止しました。');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($toImport, $actorId, $taxRate) {
            foreach ($toImport as $m) {
                $member = ZealMember::create($m->memberAttributes + [
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                if ($m->contractAttributes !== null) {
                    ZealMemberContract::create($m->contractAttributes + [
                        'member_id'            => $member->id,
                        'is_campaign_applied'  => false,
                        'tax_rate_at_contract' => $taxRate,
                        'created_by'           => $actorId,
                    ]);
                }
            }
        });

        $this->info(count($toImport) . " 件を取り込みました（税率 {$taxRate}%）。");
        return self::SUCCESS;
    }
}
