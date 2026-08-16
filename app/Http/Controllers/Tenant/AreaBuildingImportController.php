<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Support\FloorNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビル調査の Excel 取込（設計 §7）。
 *
 * クライアント側の SheetJS がシート選択・ヘッダー行選択・列マッピング・プレビューまで行い、
 * 正規化済みの行を hidden の JSON として POST してくる。サーバ側でもう一度正規化する
 * （画面を経由しない POST でも壊れたデータが入らないようにするため）。
 *
 * ⚠ `fetch` は使わない。GET の fetch にヘッダーを付け忘れる Bug #35 に触れないうえ、
 *   AjaxErrorFeedbackTest::test_every_fetch_view_is_classified の分類対象にもならない。
 *
 * ⚠ **黙って値を捨てる経路を作らない。** 非空なのに読めなかった列はその行を取り込まず
 *   件数で報告する（設計 §7.3）。2026-08-17 のレビューまで階だけが例外で、`'1F'` `'B1'`
 *   `'2階'` が無警告で NULL になっていた。正規化は `App\Support\FloorNumber` に集約し、
 *   プレビュー（JS）と同じ判定を使う（Bug #41）。
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
    /*
     * ⚠ 下の 5 つは **public**。取込プレビュー（import.blade.php の AREA_IMPORT_LIMITS）が
     *   同じ範囲で警告を出す必要があり、割れると「画面が取り込めると言った行をサーバが弾く」
     *   （Bug #41）。一致は AreaBuildingImportTest::test_limits_match_between_php_and_js が固定する。
     */

    /** 1 回の取込で受け付ける最大行数 */
    private const MAX_ROWS = 2000;

    /**
     * 件数欄の上限。列は INT UNSIGNED で、画面 CRUD も max:9999。
     * ⚠ SQLite は範囲を強制しないので、これが無いと本番 MySQL（strict）でだけ
     *   1264 Out of range で 500 になる（Bug #40）。
     */
    public const MAX_COUNT = 9999;

    /** 総階数の範囲。画面 CRUD の min:0|max:200 と同じ */
    public const MIN_FLOORS = 0;

    public const MAX_FLOORS = 200;

    /** テナントの階の範囲。地下は負数（B1 = -1）。画面 CRUD の min:-10|max:200 と同じ */
    public const MIN_TENANT_FLOOR = -10;

    /**
     * 調査年月の下限。画面 CRUD の after_or_equal:1900-01 と同じ。
     * ⚠ 下限が無いと '0000-06' のような値が MySQL の DATE に入らず 1292 で落ちる。
     */
    public const MIN_YEAR = 1900;

    /** 結果メッセージに名前を並べる上限 */
    private const MAX_NAMES_IN_MESSAGE = 10;

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
            // ⚠ in: を外すと未知の kind が黙ってテナント取込へ落ちる
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

        $map   = $this->buildingMapByNormalizedName();
        $taken = $this->existingSurveyMonths($map);

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

            // ⚠ 総階数が非空なのに読めない / 範囲外なら**行ごと**取り込まない（設計 §7.3）。
            //   黙って null に落とすと、利用者は入力したはずの階数が消えたことに気づけない。
            $floors = $this->parseFloors($row['total_floors'] ?? null);
            if ($floors === false) {
                $invalid++;
                continue;
            }

            $month   = $this->parseMonth($row['surveyed_month'] ?? null) ?? $defaultMonth;
            $address = $this->nullableString($row['address'] ?? null, 255);

            if (isset($map[$key])) {
                $building = $map[$key];

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
                    // ⚠ VARCHAR(255) の防波堤。SQLite は長さを強制しないので、外すと
                    //   本番 MySQL でだけ 1406 で 500 になる（Bug #40）
                    'name'         => mb_substr($rawName, 0, 255),
                    'address'      => $address,
                    'total_floors' => $floors,
                    'created_by'   => $userId,
                ]);
                // ⚠ ここで map に載せないと、同じファイル内の同名の行が別のビルを作る
                $map[$key] = $building;
                $created++;
            }

            // 同じビル・同じ調査年月が既にあれば取り込まずスキップする
            // ⚠ SQL の whereDate をやめて先読み済みの集合で判定する（1 行ごとに
            //   exists() を投げると 2000 行で 2000 往復になる）。日付は date キャスト後に
            //   'Y-m-d' へ揃えるので MySQL / SQLite の比較差にも影響されない。
            $monthKey = $building->id . '|' . $month . '-01';
            if (isset($taken[$monthKey])) {
                $skipped++;
                continue;
            }

            try {
                AreaBuildingSurvey::create(array_merge($counts, [
                    'area_building_id' => $building->id,
                    'surveyed_month'   => $month . '-01',
                    'surveyed_by'      => $userId,
                ]));
                $taken[$monthKey] = true;
                $added++;
            } catch (UniqueConstraintViolationException) {
                // ⚠ **並行リクエスト専用のバックストップ。** 同一ファイル内の重複は上の
                //   $taken が拾うので、1 リクエスト内では原理的にここへ来ない
                //   （2026-08-17 実測: 同一ビル・同一月の 2 行で creating は 1 回しか発火しない）。
                //   ⚠ 汎用の QueryException で受けないこと — 桁あふれ等を「同一年月」と
                //     偽って報告してしまう。ここは重複だけを黙って飲み込む。
                $skipped++;
            }
        }

        return sprintf(
            '取込が完了しました。ビル新規 %d 件 / 調査追加 %d 件 / 同一年月のためスキップ %d 件 / 値が不正でスキップ %d 件 / ビル名が空でスキップ %d 件',
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
        $created        = 0;
        $blank          = 0;
        $invalid        = 0;
        $unmatchedRows  = 0;
        $unmatchedNames = [];
        $touched        = [];

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
                $unmatchedRows++;
                $unmatchedNames[$key] = true;
                continue;
            }

            // ⚠ 階も非空で読めない / 範囲外なら行ごと弾く（黙って NULL にしない）
            $floor = $this->parseTenantFloor($row['floor'] ?? null);
            if ($floor === false) {
                $invalid++;
                continue;
            }

            $building = $map[$key];

            AreaBuildingTenant::create([
                'area_building_id' => $building->id,
                'floor'            => $floor,
                'room_number'      => $this->nullableString($row['room_number'] ?? null, 50),
                'name'             => $this->nullableString($row['name'] ?? null, 255),
                'industry'         => $this->nullableString($row['industry'] ?? null, 100),
                'status'           => AreaTenantStatus::fromRawLabel($this->text($row['status'] ?? null))->value,
            ]);
            $created++;
            $touched[$building->id] = $building;
        }

        $message = sprintf(
            '取込が完了しました。テナント登録 %d 件 / ビル名が空でスキップ %d 件 / 値が不正でスキップ %d 件 / 台帳に無いビルでスキップ %d 行',
            $created,
            $blank,
            $invalid,
            $unmatchedRows
        );

        if ($unmatchedNames !== []) {
            $message .= sprintf('（%d 棟: %s）', count($unmatchedNames), $this->joinNames(array_keys($unmatchedNames)));
        }

        // ⚠ 再取込は行を二重にする（突合キーが設計に無いので重複判定ができない）。
        //   `AreaBuildingController::divergence()` が現況テナント数と調査回の件数を
        //   突き合わせるため、二重取込は乖離警告に嘘の数字を出させる。
        //   せめて「今そのビルに何件あるか」を返して、その場で気づけるようにする。
        if ($touched !== []) {
            $message .= ' 取込後の現況テナント数: ' . $this->currentTenantTotals($touched);
        }

        return $message;
    }

    /**
     * 取込対象になったビルの現況テナント数。
     *
     * @param  array<int, AreaBuilding>  $buildings
     */
    private function currentTenantTotals(array $buildings): string
    {
        $counts = AreaBuildingTenant::whereIn('area_building_id', array_keys($buildings))
            ->whereNull('moved_out_on')
            ->selectRaw('area_building_id, COUNT(*) as aggregate')
            ->groupBy('area_building_id')
            ->pluck('aggregate', 'area_building_id');

        $shown = array_slice($buildings, 0, self::MAX_NAMES_IN_MESSAGE, true);
        $parts = [];
        foreach ($shown as $id => $building) {
            $parts[] = $building->name . ' ' . (int) ($counts[$id] ?? 0) . ' 件';
        }

        $text = implode(' / ', $parts);
        if (count($buildings) > count($shown)) {
            $text .= sprintf(' ほか %d 棟', count($buildings) - count($shown));
        }

        return $text;
    }

    /** @param  list<string>  $names */
    private function joinNames(array $names): string
    {
        $shown = array_slice($names, 0, self::MAX_NAMES_IN_MESSAGE);
        $text  = implode(' / ', $shown);

        if (count($names) > count($shown)) {
            $text .= sprintf(' ほか %d 件', count($names) - count($shown));
        }

        return $text;
    }

    // ============================================================
    // 正規化ヘルパー
    // ============================================================

    /**
     * 正規化したビル名 → ビル。
     *
     * ⚠ 同じキーに複数のビルがぶら下がる場合は **id の小さいほう**を採る（後勝ちにしない）。
     *   `orderBy('id')` がそれを担保している。
     * ⚠ **モデルごと持つ**（id だけにすると 1 行ごとに find() が飛び、100 行で 100 往復になる）。
     *   台帳が数千棟になったら SQL 側の突合へ移す。現状の想定は数十〜数百棟。
     * ⚠ SoftDeletes は含めない（削除済みのビルに調査回を足さない）。同名で登録し直される。
     *
     * @return array<string, AreaBuilding>
     */
    private function buildingMapByNormalizedName(): array
    {
        $map = [];

        foreach (AreaBuilding::orderBy('id')->get(['id', 'name', 'address', 'total_floors']) as $building) {
            $key = AreaBuilding::normalizeName($building->name);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $building;
            }
        }

        return $map;
    }

    /**
     * 既に登録済みの「ビル id + 調査年月」の集合を 1 クエリで先読みする。
     *
     * @param  array<string, AreaBuilding>  $map
     * @return array<string, true>
     */
    private function existingSurveyMonths(array $map): array
    {
        if ($map === []) {
            return [];
        }

        $ids = array_map(static fn (AreaBuilding $b) => $b->id, array_values($map));

        $taken = [];
        foreach (AreaBuildingSurvey::whereIn('area_building_id', $ids)->get(['area_building_id', 'surveyed_month']) as $survey) {
            $taken[$survey->area_building_id . '|' . $survey->surveyed_month->format('Y-m-d')] = true;
        }

        return $taken;
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
     * 総階数。'10階建' '地上5階' も読む。地下は総階数として不正。
     *
     * @return int|null|false null = 空欄 / false = 読めない・範囲外（行ごと弾く）
     */
    private function parseFloors(mixed $raw): int|null|false
    {
        return $this->bounded(FloorNumber::parse($raw, false), self::MIN_FLOORS, self::MAX_FLOORS);
    }

    /**
     * テナントの階。'1F' 'B1' '2階' '地下1階' を読む（地下は負数）。
     *
     * @return int|null|false null = 空欄 / false = 読めない・範囲外（行ごと弾く）
     */
    private function parseTenantFloor(mixed $raw): int|null|false
    {
        return $this->bounded(FloorNumber::parse($raw, true), self::MIN_TENANT_FLOOR, self::MAX_FLOORS);
    }

    private function bounded(int|null|false $value, int $min, int $max): int|null|false
    {
        if (! is_int($value)) {
            return $value;      // null（空欄）/ false（読めない）はそのまま
        }

        return ($value >= $min && $value <= $max) ? $value : false;
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
        //   （parseCount / bounded）が必ず範囲で弾くので、ここで桁数を制限しない。
        //   ⚠ 桁数の上限を重ねると、範囲チェックを壊す変異が検出できなくなる（Bug #48）。
        return preg_match('/\A-?\d+\z/', $s) === 1 ? (int) $s : false;
    }

    /**
     * 「2026年8月」「2026/08」「2026-08-15」などを 'Y-m' に正規化する。
     *
     * ⚠ 画面側は Excel の日付セルを 'YYYY-MM-DD' に整形して送ってくる
     *   （import.blade.php の areaImportCellText()）。日付セルをそのまま送るとシリアル値
     *   '45809' になり、ここで読めず無音で画面の既定月に落ちる（2026-08-17 実測）。
     *   ⚠ 読めなかったことはプレビューが警告する（areaImportMonthIsReadable）。
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

        // ⚠ mb_substr は VARCHAR 長の防波堤。SQLite は長さを強制しないので、外すと
        //   本番 MySQL でだけ 1406 Data too long で 500 になる（Bug #40）
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
