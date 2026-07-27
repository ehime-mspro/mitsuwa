<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Support\Settings;
use App\Support\Zeal\HacomonoCsvReader;
use App\Support\Zeal\HacomonoMemberMapper;
use App\Support\Zeal\MappedMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL 会員 CSV インポートコントローラ
 *
 * 別の会員管理システムがエクスポートする 77 列形式の会員 CSV を取り込む。
 * 実証済みの HacomonoCsvReader + HacomonoMemberMapper に委譲し、区分判定
 * (在籍/退会済/休会/チケット/定期OFF)・プラン名解決・税抜判別を行う。
 *
 * 2ステップ方式:
 *   1. preview(): CSV をパース → 区分判定 → プレビュー表示
 *   2. execute(): base64 で持ち回った CSV を再パース → DB 登録（会員 + 契約）
 *
 * 取込対象外(ビジター)は除外、エラー行はスキップ、同名+同入会日は重複スキップ。
 */
class ZealMemberImportController extends Controller
{
    // ================================================================
    // 画面表示
    // ================================================================

    /**
     * インポート画面表示
     * Route: GET /admin/zeal/member-import
     */
    public function index()
    {
        return view('admin.zeal-member-import.index');
    }

    // ================================================================
    // プレビュー
    // ================================================================

    /**
     * CSV をパース → 区分判定 → プレビュー表示
     * Route: POST /admin/zeal/member-import/preview
     */
    public function preview(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        $mapper = $this->makeMapper();
        if ($mapper instanceof \Illuminate\Http\RedirectResponse) {
            return $mapper;
        }

        /** @var MappedMember[] $toImport */ $toImport = [];
        /** @var MappedMember[] $skipped */  $skipped  = [];
        /** @var MappedMember[] $errored */  $errored  = [];
        $excluded = []; // ['name' => , 'status' => , 'reason' => ] ビジター・テスト用アカウント等（取込対象外）

        foreach ($rows as $row) {
            if (!HacomonoMemberMapper::isInScope($row)) {
                $name   = trim($row['名前'] ?? '');
                $status = trim($row['状態'] ?? '');
                // テスト用アカウントは状態が「会員」のまま除外されるので、理由を明示しないと誤解を招く
                $excluded[] = [
                    'name'   => $name,
                    'status' => $status,
                    'reason' => HacomonoMemberMapper::isTestAccountName($name)
                        ? '本部テスト用アカウント'
                        : '状態「' . ($status ?: '空') . '」',
                ];
                continue;
            }
            $m = $mapper->map($row);
            if ($m->hasErrors()) {
                $errored[] = $m;
                continue;
            }
            if ($this->isDuplicate($m)) {
                $skipped[] = $m;
                continue;
            }
            $toImport[] = $m;
        }

        return view('admin.zeal-member-import.preview', compact(
            'toImport', 'skipped', 'errored', 'excluded', 'content'
        ));
    }

    // ================================================================
    // 実行
    // ================================================================

    /**
     * プレビュー確認後の実際の取込処理
     * Route: POST /admin/zeal/member-import/execute
     *
     * エラー行はスキップして取込を続行する（Web は部分取込許容。CLI の全件中断とは方針を変える）。
     */
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows] = $result;

        $mapper = $this->makeMapper();
        if ($mapper instanceof \Illuminate\Http\RedirectResponse) {
            return $mapper;
        }

        $taxRate = Settings::taxRate();
        $actorId = auth()->id();

        $imported = 0;
        $skipped  = 0;
        $errored  = 0;
        $excluded = 0;

        DB::transaction(function () use (
            $rows, $mapper, $taxRate, $actorId,
            &$imported, &$skipped, &$errored, &$excluded
        ) {
            foreach ($rows as $row) {
                if (!HacomonoMemberMapper::isInScope($row)) {
                    $excluded++;
                    continue;
                }
                $m = $mapper->map($row);
                if ($m->hasErrors()) {
                    $errored++;
                    continue;
                }
                if ($this->isDuplicate($m)) {
                    $skipped++;
                    continue;
                }

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

                $imported++;
            }
        });

        return redirect()
            ->route('admin.zeal.member-import')
            ->with('success', "インポート完了: 登録 {$imported}件 / スキップ {$skipped}件 / エラー {$errored}件 / 除外 {$excluded}件");
    }

    /**
     * プラン/店舗マスタから Mapper を生成する。有効店舗が無ければリダイレクトを返す。
     *
     * @return HacomonoMemberMapper|\Illuminate\Http\RedirectResponse
     */
    private function makeMapper()
    {
        $planIdMap    = ZealPlan::pluck('id', 'name')->toArray();
        $planPriceMap = ZealPlan::pluck('regular_price_excl', 'name')->toArray();
        $storeIdMap   = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
        if (!$defaultStore) {
            return back()->with('error', '有効な店舗が登録されていません。先に店舗マスタを登録してください。');
        }

        return new HacomonoMemberMapper(
            $planIdMap,
            $planPriceMap,
            $storeIdMap,
            $defaultStore->id,
            Settings::taxRate()
        );
    }

    /** 氏名 + 入会日で既存会員と重複するか（DATE 列を MySQL/SQLite 共通で比較するため whereDate） */
    private function isDuplicate(MappedMember $m): bool
    {
        return ZealMember::where('name', $m->displayName)
            ->whereDate('joined_on', $m->memberAttributes['joined_on'])
            ->exists();
    }

    // ================================================================
    // ヘルパー
    // ================================================================

    /**
     * CSV を読み込み、行データの配列と元 CSV 文字列を返す。
     * confirmed=1（プレビュー確認後）は base64 から復元する。
     *
     * @return array{0: array<int,array<string,string>>, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function loadCsv(Request $request)
    {
        if ($request->boolean('confirmed')) {
            // 不正 base64 は false を返すため空文字へ明示キャスト（後続で「データなし」エラーに落ちる）
            $content = (string) base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $content = file_get_contents($request->file('csv_file')->getRealPath());

            // 保存する base64 を常に UTF-8 へ揃える（confirmed 経路の再パースを安定させる）
            $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        }

        // 引用フィールド内改行（顧客内部カルテの複数行）に対応するため readContent を使う
        $rows = HacomonoCsvReader::readContent($content);
        if (count($rows) === 0) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        // 新フォーマットの主要列が無ければ「形式違い」として弾く
        $first = $rows[0];
        foreach (['名前', '入会日', '状態'] as $required) {
            if (!array_key_exists($required, $first)) {
                return back()->with('error', "CSVの形式が異なります（必須列「{$required}」が見つかりません）。会員管理システムからエクスポートしたCSVをアップロードしてください。");
            }
        }

        return [$rows, $content];
    }

}
