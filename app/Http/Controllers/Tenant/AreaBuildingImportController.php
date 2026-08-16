<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビル調査の Excel 取込（設計 §7）。
 *
 * クライアント側の SheetJS がシート選択・列マッピング・プレビューまで行い、
 * 正規化済みの行を hidden の JSON として POST してくる。サーバ側でもう一度正規化する
 * （画面を経由しない POST でも壊れたデータが入らないようにするため）。
 *
 * ⚠ `fetch` は使わない。GET の fetch にヘッダーを付け忘れる Bug #35 に触れないうえ、
 *   AjaxErrorFeedbackTest::test_every_fetch_view_is_classified の分類対象にもならない。
 *
 * ⚠ **取込全体を 1 つの DB::transaction で囲まない**（Bug #48 の「安全網を入れない判断にも
 *   理由を書き残す」に従って明記する）。理由は 3 つ:
 *     ① 2000 行のうち 1 行が失敗しただけで全部巻き戻ると、利用者は原因の行を特定できないまま
 *        最初からやり直しになる。行ごとの成否を件数で報告するほうが運用に合う
 *     ② ビル＋調査は再実行が安全（同一年月はスキップする）なので、途中まで入っていても
 *        同じファイルを流し直せば残りが埋まる
 *     ③ 2000 行ぶんの INSERT を 1 トランザクションに抱えると本番 MySQL でロックが長く残る
 *   ⚠ 逆に「ビル行だけ作られて調査回が入らない」孤児は起こりうる。②の再実行で埋まる。
 */
class AreaBuildingImportController extends Controller
{
    /** 1 回の取込で受け付ける最大行数 */
    private const MAX_ROWS = 2000;

    /**
     * 件数欄の上限。列は INT UNSIGNED で、画面 CRUD も max:9999。
     * ⚠ SQLite は範囲を強制しないので、これが無いと本番 MySQL（strict）でだけ
     *   1264 Out of range で 500 になる（Bug #40）。
     */
    private const MAX_COUNT = 9999;

    /** 総階数の範囲。画面 CRUD の min:0|max:200 と同じ */
    private const MIN_FLOORS = 0;

    private const MAX_FLOORS = 200;

    /** テナントの階の範囲。地下は負数（B1 = -1）。画面 CRUD の min:-10|max:200 と同じ */
    private const MIN_TENANT_FLOOR = -10;

    /**
     * 調査年月の下限。画面 CRUD の after_or_equal:1900-01 と同じ。
     * ⚠ 下限が無いと '0000-06' のような値が MySQL の DATE に入らず 1292 で落ちる。
     */
    private const MIN_YEAR = 1900;

    public function form()
    {
        return view('tenant.area-buildings.import');
    }

    public function execute(Request $request)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現
        //   /validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s にマッチせず、このコントローラのキーが
        //   和名チェックから丸ごと外れる。
        $validated = $request->validate([
            'kind' => 'required|in:buildings,tenants',
            // ⚠ 下限が要る理由は MIN_YEAR のコメントを参照（Safari は month 入力をただの
            //   テキスト欄として描画するので '0000-01' が現実に届きうる）
            'surveyed_month' => 'required_if:kind,buildings|nullable|date_format:Y-m|after_or_equal:1900-01',
            'rows'           => 'required|string',
        ], [
            // ⚠ 第2引数が messages。既定の required_if は「取込種別がbuildingsの場合、…」と
            //   内部値がそのまま出るので、この画面用に上書きする
            'surveyed_month.required_if' => '調査年月は必須です。',
        ], [
            // ⚠ 第3引数が attributes（Bug #37）。グローバルの rows は「原価明細」なので上書きする
            'rows' => '取込データ',
        ]);

        $rows = json_decode($validated['rows'], true);

        if (! is_array($rows) || $rows === []) {
            return back()->with('error', '取り込む行がありません。');
        }

        if (count($rows) > self::MAX_ROWS) {
            return back()->with('error', '一度に取り込めるのは ' . self::MAX_ROWS . ' 行までです。ファイルを分割してください。');
        }

        $message = $validated['kind'] === 'buildings'
            ? $this->importBuildings($rows, $validated['surveyed_month'], (int) Auth::id())
            : $this->importTenants($rows);

        return redirect()->route('tenant.area-buildings.index')->with('success', $message);
    }

    // ============================================================
    // ビル＋調査
    // ============================================================

    private function importBuildings(array $rows, string $defaultMonth, int $userId): string
    {
        $created = 0;
        $added   = 0;
        $skipped = 0;
        $invalid = 0;
        $blank   = 0;

        $map = $this->buildingMapByNormalizedName();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $blank++;
                continue;
            }

            $rawName = $this->text($row['name'] ?? null);
            $key     = AreaBuilding::normalizeName($rawName);
            if ($key === '') {
                $blank++;
                continue;
            }

            $counts = [
                'operating_count' => $this->parseCount($row['operating'] ?? null),
                'vacant_count'    => $this->parseCount($row['vacant'] ?? null),
                'unknown_count'   => $this->parseCount($row['unknown'] ?? null),
            ];
            if (in_array(null, $counts, true)) {
                $invalid++;
                continue;
            }

            $month   = $this->parseMonth($row['surveyed_month'] ?? null) ?? $defaultMonth;
            $floors  = $this->parseBounded($row['total_floors'] ?? null, self::MIN_FLOORS, self::MAX_FLOORS);
            $address = $this->nullableString($row['address'] ?? null, 255);

            if (isset($map[$key])) {
                $building = AreaBuilding::find($map[$key]);
                if ($building === null) {
                    $invalid++;
                    continue;
                }

                // 既存ビルは「空の項目だけ」Excel の値で補完する（既存の値は上書きしない）
                $fill = [];
                if (blank($building->address) && filled($address)) {
                    $fill['address'] = $address;
                }
                if ($building->total_floors === null && $floors !== null) {
                    $fill['total_floors'] = $floors;
                }
                if ($fill !== []) {
                    $building->update($fill);
                }
            } else {
                $building = AreaBuilding::create([
                    'name'         => mb_substr($rawName, 0, 255),
                    'address'      => $address,
                    'total_floors' => $floors,
                    'created_by'   => $userId,
                ]);
                $map[$key] = $building->id;
                $created++;
            }

            // 同じビル・同じ調査年月が既にあれば取り込まずスキップする
            // ⚠ whereDate で見る（= 比較は MySQL と SQLite で割れうる。Task 9 の注記を参照）
            if ($building->surveys()->whereDate('surveyed_month', $month . '-01')->exists()) {
                $skipped++;
                continue;
            }

            try {
                AreaBuildingSurvey::create(array_merge($counts, [
                    'area_building_id' => $building->id,
                    'surveyed_month'   => $month . '-01',
                    'surveyed_by'      => $userId,
                ]));
                $added++;
            } catch (UniqueConstraintViolationException) {
                // ⚠ UNIQUE のバックストップ（同じファイル内に同一ビル・同一月が 2 行あった場合）。
                //   ⚠ 汎用の QueryException で受けないこと — 桁あふれ等を「同一年月」と
                //     偽って報告してしまう。ここは重複だけを黙って飲み込む。
                $skipped++;
            }
        }

        return sprintf(
            '取込が完了しました。ビル新規 %d 件 / 調査追加 %d 件 / 同一年月のためスキップ %d 件 / 数値不正でスキップ %d 件 / ビル名が空でスキップ %d 件',
            $created,
            $added,
            $skipped,
            $invalid,
            $blank
        );
    }

    // ============================================================
    // テナント明細
    // ============================================================

    private function importTenants(array $rows): string
    {
        $created   = 0;
        $blank     = 0;
        $unmatched = [];

        $map = $this->buildingMapByNormalizedName();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $blank++;
                continue;
            }

            $key = AreaBuilding::normalizeName($this->text($row['building_name'] ?? null));
            if ($key === '') {
                $blank++;
                continue;
            }

            // 台帳に無いビル名の行は取り込まない（ビルの自動生成はしない。設計 §7.2）
            if (! isset($map[$key])) {
                $unmatched[$key] = true;
                continue;
            }

            AreaBuildingTenant::create([
                'area_building_id' => $map[$key],
                'floor'            => $this->parseBounded($row['floor'] ?? null, self::MIN_TENANT_FLOOR, self::MAX_FLOORS),
                'room_number'      => $this->nullableString($row['room_number'] ?? null, 50),
                'name'             => $this->nullableString($row['name'] ?? null, 255),
                'industry'         => $this->nullableString($row['industry'] ?? null, 100),
                'status'           => AreaTenantStatus::fromRawLabel($this->text($row['status'] ?? null))->value,
            ]);
            $created++;
        }

        $message = sprintf(
            '取込が完了しました。テナント登録 %d 件 / ビル名が空でスキップ %d 件 / 台帳に無いビルでスキップ %d 件',
            $created,
            $blank,
            count($unmatched)
        );

        if ($unmatched !== []) {
            $names = array_keys($unmatched);
            $shown = array_slice($names, 0, 10);
            $message .= '（' . implode(' / ', $shown);
            if (count($names) > count($shown)) {
                $message .= sprintf(' ほか %d 件', count($names) - count($shown));
            }
            $message .= '）';
        }

        return $message;
    }

    // ============================================================
    // 正規化ヘルパー
    // ============================================================

    /**
     * 正規化したビル名 → id。
     *
     * ⚠ 同じキーに複数のビルがぶら下がる場合は id の小さいほうを採る（後勝ちにしない）。
     * ⚠ 台帳が数千棟になったら SQL 側の突合へ移す。現状の想定は数十〜数百棟。
     * ⚠ SoftDeletes は含めない（削除済みのビルに調査回を足さない）。同名で登録し直される。
     *
     * @return array<string, int>
     */
    private function buildingMapByNormalizedName(): array
    {
        $map = [];

        foreach (AreaBuilding::orderBy('id')->get(['id', 'name']) as $building) {
            $key = AreaBuilding::normalizeName($building->name);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $building->id;
            }
        }

        return $map;
    }

    /**
     * 件数欄。空欄は 0、数値にならない値・範囲外は null（＝その行を取り込まない）。
     */
    private function parseCount(mixed $raw): ?int
    {
        $value = $this->parseInt($raw);

        if ($value === null) {
            return 0;       // 空欄は 0
        }
        if ($value === false) {
            return null;    // 数値として解釈できない
        }

        return ($value >= 0 && $value <= self::MAX_COUNT) ? $value : null;
    }

    /**
     * 階数のような「任意の整数列」。読めない値・範囲外は null（未設定）に落とす。
     * ⚠ 行そのものは捨てない — 件数と違って集計に効かない補助情報なので、
     *   1 列の汚れで調査回を落とすほうが損失が大きい。
     */
    private function parseBounded(mixed $raw, int $min, int $max): ?int
    {
        $value = $this->parseInt($raw);

        if (! is_int($value)) {
            return null;
        }

        return ($value >= $min && $value <= $max) ? $value : null;
    }

    /**
     * 全角数字・カンマ・空白・「円」「¥」を落としてから整数として読む。
     *
     * @return int|null|false null = 空欄 / false = 数値として解釈できない
     */
    private function parseInt(mixed $raw): int|null|false
    {
        if ($raw === null) {
            return null;
        }
        if (! is_scalar($raw)) {
            return false;   // 配列・オブジェクトは数値ではない
        }

        // ⚠ mb_convert_kana は必須。/u 付きの \d は全角数字にも一致するが、
        //   (int) '１２３' は 0 になるので、判定の前に半角へ寄せる必要がある。
        $s = mb_convert_kana(trim((string) $raw), 'n');                 // 全角数字 → 半角
        // ⚠ \x{3000} は冗長（/u が PCRE2_UCP を立てるので \s が U+3000 に当たる）。
        //   UCP 無効なビルドへの保険として残している。
        $s = preg_replace('/[,，\s\x{3000}円¥￥]/u', '', $s);

        if ($s === '') {
            return null;
        }

        // ⚠ 桁あふれは (int) が PHP_INT_MAX / PHP_INT_MIN に飽和させるが、呼び出し元
        //   （parseCount / parseBounded）が必ず範囲で弾くので、ここで桁数を制限しない。
        //   ⚠ 桁数の上限を重ねると、範囲チェックを壊す変異が検出できなくなる（Bug #48）。
        return preg_match('/\A-?\d+\z/', $s) === 1 ? (int) $s : false;
    }

    /**
     * 「2026年8月」「2026/08」「2026-08-15」などを 'Y-m' に正規化する。
     *
     * ⚠ 画面側は Excel の日付セルを 'YYYY-MM-DD' に整形して送ってくる
     *   （import.blade.php の cellText()）。日付セルをそのまま送るとシリアル値
     *   '45809' になり、ここで読めず無音で画面の既定月に落ちる（2026-08-17 実測）。
     */
    private function parseMonth(mixed $raw): ?string
    {
        if ($raw === null || ! is_scalar($raw)) {
            return null;
        }

        $s = mb_convert_kana(trim((string) $raw), 'n');
        $s = str_replace(['年', '/', '.'], ['-', '-', '-'], $s);
        // ⚠ rtrim($s, '月') は**バイト単位**で削るので、末尾が「曜」(E6 9B 9C) のように
        //   バイトを共有する文字だと壊れた UTF-8 を作る。文字として落とす。
        $s = preg_replace('/月\z/u', '', $s);

        if (preg_match('/\A(\d{4})-(\d{1,2})(?:-\d{1,2})?\z/', $s, $m) !== 1) {
            return null;
        }

        $year  = (int) $m[1];
        $month = (int) $m[2];

        if ($year < self::MIN_YEAR || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    private function nullableString(mixed $raw, int $max): ?string
    {
        $s = $this->text($raw);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /**
     * セルの値を文字列にする。
     * ⚠ rows は利用者が組み立てた任意の JSON なので、素の (string) キャストだと
     *   配列が来たときに "Array to string conversion" が出る。
     */
    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
