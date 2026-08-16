<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 周辺ビル調査(テナント管理)。
 *
 * 権限は routes/web.php 側のミドルウェアで担保する(設計 §8):
 *   閲覧 = 全ロール / 登録・編集 = role:executive,manager / 削除 = role:executive
 */
class AreaBuildingController extends Controller
{
    /**
     * 1 回の一括取得で Google に投げる上限（設計 §7.4）。
     * ⚠ 無制限にすると、取込ミスで大量の行が入ったときにそのままリクエストが飛ぶ。
     */
    public const GEOCODE_BATCH_LIMIT = 200;

    public function index(Request $request, AreaBuildingListService $service)
    {
        $canEdit = $request->user()->role->isManagerOrAbove();

        // 座標未取得の候補は、ボタンを出す人にだけ渡す（画面に住所を撒かない・無駄な検索もしない）
        $pendingCount = $canEdit ? AreaBuilding::pendingGeocodeCount() : 0;
        $pending      = $pendingCount > 0
            ? AreaBuilding::pendingGeocode(self::GEOCODE_BATCH_LIMIT)
                ->map(fn (AreaBuilding $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])
                ->values()
                ->all()
            : [];

        return view('tenant.area-buildings.index', [
            'rows'                => $service->paginate($request),
            'surveyYears'         => $service->surveyYears(),
            'vacancyOptions'      => AreaBuildingListService::VACANCY_OPTIONS,
            'pendingGeocode'      => $pending,
            'pendingGeocodeCount' => $pendingCount,
            'geocodeBatchLimit'   => self::GEOCODE_BATCH_LIMIT,
        ]);
    }

    /**
     * ブラウザで取得した座標をまとめて保存する（設計 §7.4）。
     *
     * ⚠ 既に座標がある行は更新しない。手で直した位置を一括処理で潰さないため。
     */
    public function storeCoordinates(Request $request)
    {
        // ⚠ 1 行にまとめると走査正規表現の `\n\s*\]` 要件を満たさず、和名チェックの
        //   対象から外れる（2026-08-16 実測）。閉じ括弧を行頭に置く形で書くこと。
        $validated = $request->validate([
            'coordinates' => 'required|string',
        ], [], [
            'coordinates' => '取得した座標',
        ]);

        $decoded = json_decode($validated['coordinates'], true);

        if (! is_array($decoded)) {
            return redirect()->route('tenant.area-buildings.index')
                ->with('error', '座標データを解釈できませんでした。もう一度お試しください。');
        }

        $updated = 0;

        // ⚠ **ここは意図的に DB::transaction() で囲まない。** 囲むのは積極的に間違い:
        //   ① Geocoding API の課金はブラウザ側で**既に発生済み**。199 行目で落ちて 198 件を
        //      巻き戻すと、もう一度 Google に払い直すことになる
        //   ② `whereNull('latitude')` ガードがあるので**再実行が安全**
        //      （部分成功のまま押し直せば残りだけ埋まる）
        //   ③ 親子関係のある書き込みではなく独立した N 行の更新なので原子性が要らない
        //   store()（親＋子を 1 リクエストで書く）を囲んだのとは状況が違う（Bug #48 の後半）。
        //
        // ⚠ `whereKey()->update()` はクエリビルダの一括更新なので**モデルイベントが発火しない**
        //   （saving フックの住所正規化を素通りする）。今は address を触らないので無害だが、
        //   将来フックに処理を足すときはこの経路が抜けることに注意。
        //   なお `updated_at` は Eloquent\Builder::update() が自動で足すので更新される。
        foreach (array_slice($decoded, 0, self::GEOCODE_BATCH_LIMIT) as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['latitude'], $item['longitude'])) {
                continue;
            }
            // ⚠ id も数値であることを見る。配列を渡されると whereKey() が whereIn へ化け、
            //   1 エントリで無関係な行まで一括更新されてしまう。
            if (! is_numeric($item['id']) || ! is_numeric($item['latitude']) || ! is_numeric($item['longitude'])) {
                continue;
            }

            $lat = (float) $item['latitude'];
            $lng = (float) $item['longitude'];

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }

            $updated += AreaBuilding::whereKey($item['id'])
                ->whereNull('latitude')
                ->update([
                    'latitude'  => round($lat, 7),
                    'longitude' => round($lng, 7),
                ]);
        }

        $remaining = AreaBuilding::pendingGeocodeCount();

        return redirect()->route('tenant.area-buildings.index')->with(
            'success',
            $remaining > 0
                ? "{$updated} 件の座標を保存しました。座標未設定は残り {$remaining} 件です。"
                : "{$updated} 件の座標を保存しました。座標未設定はありません。"
        );
    }

    public function show(AreaBuilding $building)
    {
        $surveys = $building->surveys()
            ->with('surveyor')
            ->orderByDesc('surveyed_month')
            ->orderByDesc('id')
            ->get();

        $latestSurvey = $surveys->first();

        $tenants = $building->tenants()
            ->orderByDesc('floor')
            ->orderBy('room_number')
            ->orderBy('id')
            ->get();

        $activeTenants   = $tenants->filter(fn ($t) => $t->isActive())->values();
        $movedOutTenants = $tenants->reject(fn ($t) => $t->isActive())->values();

        return view('tenant.area-buildings.show', [
            'building'        => $building,
            'surveys'         => $surveys,
            'latestSurvey'    => $latestSurvey,
            'activeTenants'   => $activeTenants,
            'movedOutTenants' => $movedOutTenants,
            'divergence'      => $this->divergence($latestSurvey, $activeTenants),
        ]);
    }

    public function create()
    {
        return view('tenant.area-buildings.create');
    }

    public function store(Request $request)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現
        //   /validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s にマッチせず、このコントローラのキーが
        //   和名チェックから丸ごと外れる（2026-08-16 実測）。store と update で重複するが、
        //   既存 185 ルートも同じ書き方をしている。
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'total_floors' => 'nullable|integer|min:0|max:200',
            'notes'        => 'nullable|string|max:5000',
            // 新規登録時のみ 1 回目の調査を同時に作れる（設計 §5.5）。
            // ⚠ 所見は survey_notes。ビル自身の notes と衝突するため名前を分けている
            'surveyed_month'  => 'nullable|date_format:Y-m',
            'operating_count' => 'nullable|integer|min:0|max:9999',
            'vacant_count'    => 'nullable|integer|min:0|max:9999',
            'unknown_count'   => 'nullable|integer|min:0|max:9999',
            'survey_notes'    => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            'name'    => 'ビル名',
            'address' => '所在地',
        ]);

        // ⚠ ビル本体＋初回調査の 2 書き込みを同一トランザクションで囲む（コード品質レビュー
        //   Important I-1、2026-08-17）。囲まないと、調査回の作成で DB 接続断・デッドロック等の
        //   汎用的な失敗が起きた場合にビル行だけがコミットされ、利用者からは
        //   「登録ボタンで 500 → 再送信 → 調査なしの孤児ビルと正常なビルが両方できる」に見える。
        //   ProcurementController::store() と同じ形（親作成＋子作成を DB::transaction で囲む）。
        $building = DB::transaction(function () use ($validated) {
            $building = AreaBuilding::create([
                'name'         => $validated['name'],
                'address'      => $validated['address'] ?? null,
                'latitude'     => $validated['latitude'] ?? null,
                'longitude'    => $validated['longitude'] ?? null,
                'total_floors' => $validated['total_floors'] ?? null,
                'notes'        => $validated['notes'] ?? null,
                'created_by'   => Auth::id(),
            ]);

            if (filled($validated['surveyed_month'] ?? null)) {
                AreaBuildingSurvey::create([
                    'area_building_id' => $building->id,
                    'surveyed_month'   => $validated['surveyed_month'] . '-01',
                    // 件数欄は空欄スタート。未入力は 0 として保存する
                    'operating_count'  => $validated['operating_count'] ?? 0,
                    'vacant_count'     => $validated['vacant_count'] ?? 0,
                    'unknown_count'    => $validated['unknown_count'] ?? 0,
                    'surveyed_by'      => Auth::id(),
                    'notes'            => $validated['survey_notes'] ?? null,
                ]);
            }

            return $building;
        });

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビルを登録しました。');
    }

    public function edit(AreaBuilding $building)
    {
        return view('tenant.area-buildings.edit', ['building' => $building]);
    }

    public function update(Request $request, AreaBuilding $building)
    {
        // ⚠ 編集画面に調査欄は出さない(調査は履歴側で管理する。設計 §5.5)。
        //   このルールだけを通すので、調査の項目が送られてきても validated に入らない。
        // ⚠ literal 配列で直書きする理由は store() のコメントを参照
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'total_floors' => 'nullable|integer|min:0|max:200',
            'notes'        => 'nullable|string|max:5000',
        ], [], [
            'name'    => 'ビル名',
            'address' => '所在地',
        ]);

        $building->update([
            'name'         => $validated['name'],
            'address'      => $validated['address'] ?? null,
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビル情報を更新しました。');
    }

    public function destroy(AreaBuilding $building)
    {
        // SoftDeletes。調査回とテナントは FK CASCADE だが、ビル行が残るので子も残る
        // (復元可能にするための意図どおりの挙動。設計 §8)
        $building->delete();

        return redirect()->route('tenant.area-buildings.index')
            ->with('success', 'ビルを削除しました。');
    }

    /**
     * 「調査時の実測（入力値）」と「テナント明細からの集計」の乖離（設計 §5.4 / Bug #46）。
     *
     * ⚠ 内訳と合計を別ソースのまま並べると無音で食い違う。両方を出して差があるときだけ警告する。
     * ⚠ 明細 0 行のビルでは比較しない（明細を入れていないだけで警告が出ると意味がない）。
     * ⚠ 下流の空室率は常に入力値を正とする。明細に寄せると、明細が途中までしか
     *   入っていないビルの数字が壊れる。
     *
     * @return array{input: array<string, int>, counted: array<string, int>}|null
     */
    private function divergence(?AreaBuildingSurvey $latest, Collection $activeTenants): ?array
    {
        if ($latest === null || $activeTenants->isEmpty()) {
            return null;
        }

        $input = [
            'operating' => $latest->operating_count,
            'vacant'    => $latest->vacant_count,
            'unknown'   => $latest->unknown_count,
        ];

        // ⚠ status はキャスト済みなので enum インスタンス。tryFrom() を呼ばない（Bug #22）
        $counted = [
            'operating' => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Operating)->count(),
            'vacant'    => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Vacant)->count(),
            'unknown'   => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Unknown)->count(),
        ];

        return $input === $counted ? null : ['input' => $input, 'counted' => $counted];
    }
}
