<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビルの調査回。Ajax ではなく別画面で追加・編集する（設計 §5.6）。
 *
 * 権限は routes/web.php 側のミドルウェアで担保する（設計 §8）:
 *   追加・編集 = role:executive,manager / 削除 = role:executive
 */
class AreaBuildingSurveyController extends Controller
{
    public function create(AreaBuilding $building)
    {
        return view('tenant.area-buildings.surveys.create', [
            'building'  => $building,
            'survey'    => null,
            'surveyors' => User::assignable()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AreaBuilding $building)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現
        //   /validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s にマッチせず、このコントローラのキーが
        //   和名チェックから丸ごと外れる（2026-08-16 実測）。store と update で重複するが、
        //   既存 185 ルートも同じ書き方をしている。
        $validated = $request->validate([
            'surveyed_month'  => 'required|date_format:Y-m',
            'operating_count' => 'nullable|integer|min:0|max:9999',
            'vacant_count'    => 'nullable|integer|min:0|max:9999',
            'unknown_count'   => 'nullable|integer|min:0|max:9999',
            // ⚠ `exists:users,id` は SoftDeletes を除外しない（`exists` はグローバルスコープを
            //   通らない）。これは**必要な挙動** — 調査者が退職済みの調査回を編集して保存できる
            //   ようにするため。「厳しくすべき」と後から締めないこと。
            'surveyed_by'     => 'nullable|integer|exists:users,id',
            'notes'           => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            //   この画面のラベルは「所見」。グローバルは「備考」なので上書きする
            'notes' => '所見',
        ]);

        $month = $validated['surveyed_month'] . '-01';

        if ($this->monthTaken($building, $month, null)) {
            return back()->withInput()->withErrors([
                'surveyed_month' => 'この年月の調査は既に登録されています。上書きせず、既存の調査を編集してください。',
            ]);
        }

        AreaBuildingSurvey::create($this->payload($validated, $month, $building) + [
            // 新規だけログインユーザーを既定にする（現地を歩いた担当が別なら変更できる）
            'surveyed_by' => $validated['surveyed_by'] ?? Auth::id(),
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を登録しました。');
    }

    public function edit(AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        return view('tenant.area-buildings.surveys.edit', [
            'building'  => $building,
            'survey'    => $survey,
            // 現在の調査者が無効化・削除済みでも選択肢に残す（Bug #12）
            'surveyors' => User::assignableWith($survey->surveyed_by),
        ]);
    }

    public function update(Request $request, AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現
        //   /validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s にマッチせず、このコントローラのキーが
        //   和名チェックから丸ごと外れる（2026-08-16 実測）。store と update で重複するが、
        //   既存 185 ルートも同じ書き方をしている。
        $validated = $request->validate([
            'surveyed_month'  => 'required|date_format:Y-m',
            'operating_count' => 'nullable|integer|min:0|max:9999',
            'vacant_count'    => 'nullable|integer|min:0|max:9999',
            'unknown_count'   => 'nullable|integer|min:0|max:9999',
            // ⚠ store() と同じ理由で SoftDeletes を除外しない（退職者を調査者に持つ調査回を保存できるように）
            'surveyed_by'     => 'nullable|integer|exists:users,id',
            'notes'           => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            //   この画面のラベルは「所見」。グローバルは「備考」なので上書きする
            'notes' => '所見',
        ]);

        $month = $validated['surveyed_month'] . '-01';

        if ($this->monthTaken($building, $month, $survey->id)) {
            return back()->withInput()->withErrors([
                'surveyed_month' => 'この年月の調査は既に登録されています。上書きせず、既存の調査を編集してください。',
            ]);
        }

        $survey->update($this->payload($validated, $month, $building) + [
            // ⚠ 編集は **フォームが出している値をそのまま**書く。`?? Auth::id()` にすると
            //   調査者が未送信のとき元の調査者が黙って編集者に置き換わる（`surveyed_by` は
            //   FK ON DELETE SET NULL なので DB 上 null もありうる）。フォームが描画している
            //   項目はフォームを正本にする — Task 8 の座標クリア（空 → null）と同じ扱い。Bug #38。
            'surveyed_by' => $validated['surveyed_by'] ?? null,
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を更新しました。');
    }

    public function destroy(AreaBuilding $building, AreaBuildingSurvey $survey)
    {
        $this->assertOwnedBy($building, $survey);

        // 調査回は物理削除（SoftDeletes を持たない。設計 §3.2）
        $survey->delete();

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', '調査を削除しました。');
    }

    /**
     * ⚠ ミドルウェアは部門単位でしか見ない。URL の {building} と {survey} の
     *   親子関係はここで明示的に確かめる（付け忘れると別ビルの調査回を編集・削除できる）。
     */
    private function assertOwnedBy(AreaBuilding $building, AreaBuildingSurvey $survey): void
    {
        abort_unless($survey->area_building_id === $building->id, 404);
    }

    private function monthTaken(AreaBuilding $building, string $month, ?int $ignoreId): bool
    {
        // ⚠ whereDate で見る。date キャストは $dateFormat（既定 Y-m-d H:i:s）で書き込むので、
        //   型を持たない SQLite には '2026-08-01 00:00:00' が残りうる。= 比較だと
        //   本番 MySQL とテスト SQLite で挙動が割れる危険がある。
        return $building->surveys()
            ->whereDate('surveyed_month', $month)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * store / update に共通する列だけを返す。
     * ⚠ `surveyed_by` はここに入れない — 新規は「ログインユーザーを既定」、編集は
     *   「フォームの値をそのまま」で**挙動が違う**ため、呼び出し側で `+ [...]` して足す。
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated, string $month, AreaBuilding $building): array
    {
        return [
            'area_building_id' => $building->id,
            'surveyed_month'   => $month,
            // 件数欄は空欄スタート。未入力は 0 として保存する（設計 §5.5）
            'operating_count'  => $validated['operating_count'] ?? 0,
            'vacant_count'     => $validated['vacant_count'] ?? 0,
            'unknown_count'    => $validated['unknown_count'] ?? 0,
            'notes'            => $validated['notes'] ?? null,
        ];
    }
}
