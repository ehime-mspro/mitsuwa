<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ZealAcquisitionSource;
use App\Enums\ZealContractChangeReason;
use App\Enums\ZealGender;
use App\Enums\ZealPurpose;
use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealTrainer;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL 会員 CSV インポートコントローラ
 *
 * 2ステップ方式:
 *   1. preview(): CSV をパース → バリデーション → プレビュー表示
 *   2. execute(): base64 エンコードされた CSV を再パース → DB 登録
 *
 * 取込時に zeal_member_contracts の new_join レコードも同時生成する。
 *
 * CSV カラム仕様（15列）:
 *   氏名, フリガナ, 性別, 生年月日, 電話番号, メールアドレス, 郵便番号, 住所,
 *   入会日, プラン名, 月会費（税抜）, 担当トレーナー, 集客チャネル, 入会目的, メモ
 */
class ZealMemberImportController extends Controller
{
    // ================================================================
    // カラムマッピング（CSV ヘッダー → 内部キー）
    // ================================================================

    private array $columnMap = [
        '氏名'          => 'name',
        'フリガナ'      => 'name_kana',
        '性別'          => 'gender',
        '生年月日'      => 'birthday',
        '電話番号'      => 'phone',
        'メールアドレス'=> 'email',
        '郵便番号'      => 'postal_code',
        '住所'          => 'address',
        '入会日'        => 'joined_on',
        'プラン名'      => 'plan_name',
        '月会費（税抜）'=> 'applied_price_excl',
        '担当トレーナー'=> 'trainer_name',
        '集客チャネル'  => 'acquisition_source',
        '入会目的'      => 'purpose',
        'メモ'          => 'memo',
    ];

    /** 性別: 日本語ラベル → Enum 値 */
    private array $genderMap = [
        '男性'   => 'male',
        '女性'   => 'female',
        'その他' => 'other',
    ];

    /** 集客チャネル: 日本語ラベル → Enum 値 */
    private array $acquisitionMap = [
        'SNS'             => 'sns',
        '検索エンジン'    => 'search',
        '紹介'            => 'referral',
        '口コミ'          => 'word_of_mouth',
        'ポスティングチラシ' => 'flyer',
        '街頭チラシ'      => 'street_flyer',
        '地図検索'        => 'map_search',
        '電話'            => 'phone',
        '不明'            => 'unknown',
        'その他'          => 'other',
    ];

    /** 入会目的: 日本語ラベル → Enum 値 */
    private array $purposeMap = [
        'ボディメイク'      => 'body_make',
        'ダイエット'        => 'diet',
        '運動不足解消'      => 'exercise',
        '機能改善'          => 'function',
        '下半身強化'        => 'lower_body',
        '体力向上'          => 'stamina',
        'ストレス発散'      => 'stress',
        '健康増進'          => 'health',
        'その他'            => 'other',
    ];

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
    // テンプレート CSV ダウンロード
    // ================================================================

    /**
     * サンプル CSV テンプレートをダウンロード
     * Route: GET /admin/zeal/member-import/template
     *
     * ※ ルートは routes/web.php に追加が必要な場合は別途追加してください。
     *    現在の実装では index ビューからのフォーム POST で代替しています。
     */
    public function template()
    {
        $bom = "\xEF\xBB\xBF";
        $header = array_keys($this->columnMap);

        $sampleRows = [
            ['山本 健太', 'ヤマモト ケンタ', '男性', '1992-03-14', '090-1234-5678', 'yamamoto@example.com', '790-0001', '愛媛県松山市一番町1-2-3', '2025-10-17', 'パーソナル&セミパーソナル通い放題（1枠）', '18000', '田中', 'SNS', 'ダイエット', ''],
            ['佐藤 花子', 'サトウ ハナコ', '女性', '1985-07-22', '080-9876-5432', '',               '790-0023', '愛媛県松山市本町2-3',     '2025-11-01', 'パーソナル&セミパーソナル通い放題（2枠）', '',      '',     '',      '',          '週3回希望'],
        ];

        $csv = $bom . $this->toCsvLine($header);
        foreach ($sampleRows as $row) {
            $csv .= $this->toCsvLine($row);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="zeal_member_import_template.csv"',
        ]);
    }

    // ================================================================
    // プレビュー
    // ================================================================

    /**
     * CSV をパース → バリデーション → プレビュー表示
     * Route: POST /admin/zeal/member-import/preview
     */
    public function preview(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ（名前→ID マップ）
        $planMap = ZealPlan::pluck('id', 'name')->toArray();

        // トレーナーマスタ（名前→ID マップ）
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();

        // 現在の税率（settings テーブル / 不在時は 10% フォールバック）
        $taxRate = Settings::taxRate();

        $validRows   = [];
        $errorRows   = [];
        $skippedRows = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // 1行目＝ヘッダー
            $rowErrors = [];

            // ---- 必須チェック ----
            if ($row['name'] === '') {
                $rowErrors[] = '氏名が未入力';
            }
            if ($row['name_kana'] === '') {
                $rowErrors[] = 'フリガナが未入力';
            }
            if ($row['gender'] === '') {
                $rowErrors[] = '性別が未入力';
            } elseif (!isset($this->genderMap[$row['gender']])) {
                $rowErrors[] = "性別「{$row['gender']}」は無効（男性/女性/その他）";
            }
            if ($row['joined_on'] === '') {
                $rowErrors[] = '入会日が未入力';
            } elseif (!$this->normalizeDate($row['joined_on'])) {
                $rowErrors[] = "入会日「{$row['joined_on']}」の形式が不正（YYYY-MM-DD）";
            }
            if ($row['plan_name'] === '') {
                $rowErrors[] = 'プラン名が未入力';
            } elseif (!isset($planMap[$row['plan_name']])) {
                $rowErrors[] = "プラン名「{$row['plan_name']}」が見つかりません";
            }

            // ---- 任意項目のチェック ----
            if ($row['birthday'] !== '' && !$this->normalizeDate($row['birthday'])) {
                $rowErrors[] = "生年月日「{$row['birthday']}」の形式が不正（YYYY-MM-DD）";
            }
            if ($row['applied_price_excl'] !== '' && !is_numeric($row['applied_price_excl'])) {
                $rowErrors[] = "月会費「{$row['applied_price_excl']}」は数値で入力してください";
            }
            if ($row['acquisition_source'] !== '' && !isset($this->acquisitionMap[$row['acquisition_source']])) {
                $rowErrors[] = "集客チャネル「{$row['acquisition_source']}」は無効です";
            }
            if ($row['purpose'] !== '' && !isset($this->purposeMap[$row['purpose']])) {
                $rowErrors[] = "入会目的「{$row['purpose']}」は無効です";
            }
            if ($row['trainer_name'] !== '' && !isset($trainerMap[$row['trainer_name']])) {
                $rowErrors[] = "担当トレーナー「{$row['trainer_name']}」が見つかりません";
            }

            if (!empty($rowErrors)) {
                $errorRows[] = ['row' => $rowNum, 'data' => $row, 'errors' => $rowErrors];
                continue;
            }

            // ---- 既存会員重複チェック（氏名＋入会日で簡易判定）----
            $joinedOn = $this->normalizeDate($row['joined_on']);
            $existing = ZealMember::where('name', $row['name'])
                ->where('joined_on', $joinedOn)
                ->first();
            if ($existing) {
                $skippedRows[] = ['row' => $rowNum, 'data' => $row, 'reason' => '同名・同入会日の会員が既に登録済み'];
                continue;
            }

            // ---- 有効行として記録 ----
            $planId = $planMap[$row['plan_name']];
            $plan   = ZealPlan::find($planId);

            // 月会費が空の場合はプランの通常価格を使用
            $appliedPriceExcl = ($row['applied_price_excl'] !== '')
                ? (int) $row['applied_price_excl']
                : $plan->regular_price_excl;

            $validRows[] = [
                'row_num'            => $rowNum,
                'name'               => $row['name'],
                'name_kana'          => $row['name_kana'],
                'gender'             => $this->genderMap[$row['gender']],
                'gender_label'       => $row['gender'],
                'birthday'           => $this->normalizeDate($row['birthday'] ?: ''),
                'phone'              => $row['phone'],
                'email'              => $row['email'],
                'postal_code'        => $row['postal_code'],
                'address'            => $row['address'],
                'joined_on'          => $joinedOn,
                'plan_id'            => $planId,
                'plan_name'          => $row['plan_name'],
                'applied_price_excl' => $appliedPriceExcl,
                'price_incl'         => (int) round($appliedPriceExcl * (1 + $taxRate / 100)),
                'tax_rate'           => $taxRate,
                'trainer_id'         => $row['trainer_name'] !== '' ? ($trainerMap[$row['trainer_name']] ?? null) : null,
                'trainer_name'       => $row['trainer_name'],
                'acquisition_source' => $row['acquisition_source'] !== '' ? ($this->acquisitionMap[$row['acquisition_source']] ?? null) : null,
                'purpose'            => $row['purpose'] !== '' ? ($this->purposeMap[$row['purpose']] ?? null) : null,
                'memo'               => $row['memo'],
            ];
        }

        return view('admin.zeal-member-import.preview', compact(
            'validRows', 'errorRows', 'skippedRows', 'content'
        ));
    }

    // ================================================================
    // 実行
    // ================================================================

    /**
     * プレビュー確認後の実際の取込処理
     * Route: POST /admin/zeal/member-import/execute
     */
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ・トレーナーマスタを再取得
        $planMap    = ZealPlan::pluck('id', 'name')->toArray();
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();
        $taxRate    = Settings::taxRate();

        $importedCount = 0;
        $skippedCount  = 0;
        $errorCount    = 0;

        DB::transaction(function () use (
            $rows, $planMap, $trainerMap, $taxRate,
            &$importedCount, &$skippedCount, &$errorCount
        ) {
            foreach ($rows as $row) {
                // 必須チェック（execute でも再チェック）
                if (
                    $row['name'] === ''
                    || $row['name_kana'] === ''
                    || $row['gender'] === ''
                    || $row['joined_on'] === ''
                    || $row['plan_name'] === ''
                    || !isset($this->genderMap[$row['gender']])
                    || !isset($planMap[$row['plan_name']])
                ) {
                    $errorCount++;
                    continue;
                }

                $joinedOn = $this->normalizeDate($row['joined_on']);
                if (!$joinedOn) {
                    $errorCount++;
                    continue;
                }

                // 既存チェック（重複スキップ）
                $existing = ZealMember::where('name', $row['name'])
                    ->where('joined_on', $joinedOn)
                    ->first();
                if ($existing) {
                    $skippedCount++;
                    continue;
                }

                $planId = $planMap[$row['plan_name']];
                $plan   = ZealPlan::find($planId);

                $appliedPriceExcl = ($row['applied_price_excl'] !== '' && is_numeric($row['applied_price_excl']))
                    ? (int) $row['applied_price_excl']
                    : $plan->regular_price_excl;

                // 1. ZealMember を作成
                $member = ZealMember::create([
                    'name'               => $row['name'],
                    'name_kana'          => $row['name_kana'],
                    'gender'             => $this->genderMap[$row['gender']],
                    'birthday'           => $this->normalizeDate($row['birthday'] ?: '') ?: null,
                    'phone'              => $row['phone'] ?: null,
                    'email'              => $row['email'] ?: null,
                    'postal_code'        => $row['postal_code'] ?: null,
                    'address'            => $row['address'] ?: null,
                    'joined_on'          => $joinedOn,
                    'current_plan_id'    => $planId,
                    'trainer_id'         => isset($trainerMap[$row['trainer_name']]) ? $trainerMap[$row['trainer_name']] : null,
                    'acquisition_source' => isset($this->acquisitionMap[$row['acquisition_source']]) ? $this->acquisitionMap[$row['acquisition_source']] : null,
                    'purpose'            => isset($this->purposeMap[$row['purpose']]) ? $this->purposeMap[$row['purpose']] : null,
                    'memo'               => $row['memo'] ?: null,
                    'created_by'         => auth()->id(),
                    'updated_by'         => auth()->id(),
                ]);

                // 2. 初回契約レコード（new_join）を作成
                ZealMemberContract::create([
                    'member_id'            => $member->id,
                    'plan_id'              => $planId,
                    'period_start'         => $joinedOn,
                    'period_end'           => null,
                    'applied_price_excl'   => $appliedPriceExcl,
                    'is_campaign_applied'  => false,
                    'tax_rate_at_contract' => $taxRate,
                    'change_reason'        => ZealContractChangeReason::NewJoin->value,
                    'note'                 => null,
                    'created_by'           => auth()->id(),
                ]);

                $importedCount++;
            }
        });

        return redirect()
            ->route('admin.zeal.member-import')
            ->with('success', "インポート完了: 登録 {$importedCount}件 / スキップ {$skippedCount}件 / エラー {$errorCount}件");
    }

    // ================================================================
    // ヘルパー
    // ================================================================

    /**
     * CSV ファイルを読み込み、行データの配列を返す。
     * プレビュー確認後（confirmed=1）の場合は base64 から復元する。
     *
     * @return array{0: array, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function loadCsv(Request $request)
    {
        // 確認済みの場合は base64 から CSV を復元
        if ($request->boolean('confirmed')) {
            $content = base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $file    = $request->file('csv_file');
            $content = file_get_contents($file->getRealPath());

            // Shift_JIS 自動判定 → UTF-8 変換
            $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            // BOM 除去
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        }

        $lines = array_values(array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        }));

        if (count($lines) < 2) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        $header = array_map('trim', str_getcsv(array_shift($lines)));

        // ヘッダー → 内部キーのインデックスマッピング
        $colIndex = [];
        foreach ($header as $idx => $headerName) {
            if (isset($this->columnMap[$headerName])) {
                $colIndex[$this->columnMap[$headerName]] = $idx;
            }
        }

        // 必須ヘッダーチェック
        $requiredHeaders = ['name', 'name_kana', 'gender', 'joined_on', 'plan_name'];
        foreach ($requiredHeaders as $key) {
            if (!isset($colIndex[$key])) {
                $jpName = array_search($key, $this->columnMap);
                return back()->with('error', "必須ヘッダー「{$jpName}」がCSVに見つかりません。");
            }
        }

        // 行データ抽出
        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row  = [];
            foreach ($this->columnMap as $jpName => $key) {
                $idx       = $colIndex[$key] ?? -1;
                $row[$key] = ($idx >= 0 && isset($cols[$idx])) ? trim($cols[$idx]) : '';
            }
            $rows[] = $row;
        }

        return [$rows, $content];
    }

    /**
     * 日付文字列を YYYY-MM-DD 形式に正規化する。
     * 無効な場合は null を返す。
     */
    private function normalizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) {
            $parts = explode('-', $value);
            return sprintf('%04d-%02d-%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2]);
        }
        return null;
    }

    /**
     * CSV 行文字列を生成する（ダブルクォートエスケープ）
     */
    private function toCsvLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $f) {
            $escaped[] = '"' . str_replace('"', '""', (string) $f) . '"';
        }
        return implode(',', $escaped) . "\n";
    }
}
