<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 周辺ビルの入居テナント（現況リスト）。Ajax ではなく別画面で追加・編集する（設計 §5.6）。
 *
 * 権限は routes/web.php 側のミドルウェアで担保する（設計 §8）:
 *   追加・編集 = role:executive,manager / 削除 = role:executive
 */
class AreaBuildingTenantController extends Controller
{
    public function create(AreaBuilding $building)
    {
        return view('tenant.area-buildings.tenants.create', [
            'building' => $building,
            'tenant'   => null,
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
            // 地下は負数（B1 = -1）。列は signed INT なので下限も要る
            'floor'        => 'nullable|integer|min:-10|max:200',
            // ⚠ max:50 / max:255 / max:100 は本番 MySQL（strict）の VARCHAR 長に対する防波堤。
            //   SQLite は長さを強制しないので、外してもテストは静かに緑になる（Bug #40）。
            //   AreaBuildingTenantCrudTest の表がここを固定している。
            'room_number'  => 'nullable|string|max:50',
            'name'         => 'nullable|string|max:255',
            'industry'     => 'nullable|string|max:100',
            // ⚠ `in:operating,vacant,unknown` と値を手で並べない。セレクトは
            //   `@foreach(AreaTenantStatus::cases())` で作るので、手書きだと二重管理になり
            //   Enum に case を足したとき「画面には出るのに保存できない」が無音で起きる（Bug #41 と同型）。
            //   ⚠ これを守るテスト（test_every_status_case_is_accepted_on_both_entry_points）が
            //   赤になるのは **case を足したとき**だけ。3 case のままなら
            //   `'required|in:operating,vacant,unknown'` に戻しても緑（2026-08-17 実測）。
            //   「戻した瞬間に赤くなる」と読まないこと。
            // ⚠ `required` は UX ではなく 500 の防波堤。Rule::enum は implicit rule ではないので、
            //   外すと `status` キーを持たないリクエストがルールごとスキップされ、
            //   store は NOT NULL 列を欠いた INSERT（本番 MySQL 1364）、update は
            //   payload() の `$validated['status']` で Undefined array key になる。
            'status'       => ['required', Rule::enum(AreaTenantStatus::class)],
            'confirmed_on' => 'nullable|date',
            'moved_out_on' => 'nullable|date',
            'notes'        => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            //   グローバルは name→名称 / room_number→号室 / floor→階数 / status→ステータス。
            //   この画面のラベルに合わせて上書きする（グローバル側は他の画面が使うので変えない）
            'name'        => 'テナント名',
            'room_number' => '部屋番号',
            'floor'       => '階',
            'status'      => '状態',
        ]);

        AreaBuildingTenant::create(array_merge($this->payload($validated), [
            'area_building_id' => $building->id,
        ]));

        // 「保存して続けて登録」。1 棟 10〜20 区画になるので往復を減らす（設計 §5.6）。
        // ⚠ validate() には載せない（項目名が要らないうえ、画面の入力項目ではない）
        if ($request->boolean('keep_adding')) {
            return redirect()->route('tenant.area-buildings.tenants.create', $building)
                // ⚠ チェック状態だけを持ち越す。持ち越さないと連続入力のたびに入れ直しになり、
                //   往復を減らすという目的が果たせない。⚠ 引数無しの withInput() にすると
                //   前の行の入力（テナント名など）まで次の画面に残ってしまう。
                ->withInput(['keep_adding' => '1'])
                ->with('success', 'テナントを登録しました。続けて登録できます。');
        }

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを登録しました。');
    }

    public function edit(AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        return view('tenant.area-buildings.tenants.edit', [
            'building' => $building,
            'tenant'   => $tenant,
        ]);
    }

    public function update(Request $request, AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        // ⚠ literal 配列で直書きする理由は store() のコメントを参照
        $validated = $request->validate([
            'floor'        => 'nullable|integer|min:-10|max:200',
            'room_number'  => 'nullable|string|max:50',
            'name'         => 'nullable|string|max:255',
            'industry'     => 'nullable|string|max:100',
            // ⚠ store() のコメント（Enum 二重管理 / required は 500 の防波堤）を参照
            'status'       => ['required', Rule::enum(AreaTenantStatus::class)],
            'confirmed_on' => 'nullable|date',
            'moved_out_on' => 'nullable|date',
            'notes'        => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            'name'        => 'テナント名',
            'room_number' => '部屋番号',
            'floor'       => '階',
            'status'      => '状態',
        ]);

        $tenant->update($this->payload($validated));

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを更新しました。');
    }

    public function destroy(AreaBuilding $building, AreaBuildingTenant $tenant)
    {
        $this->assertOwnedBy($building, $tenant);

        // 入居テナントは物理削除（SoftDeletes を持たない。設計 §3.3）
        $tenant->delete();

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'テナントを削除しました。');
    }

    /**
     * ⚠ ミドルウェアは部門単位でしか見ない。URL の {building} と {tenant} の
     *   親子関係はここで明示的に確かめる（付け忘れると別ビルのテナントを編集・削除できる）。
     */
    private function assertOwnedBy(AreaBuilding $building, AreaBuildingTenant $tenant): void
    {
        abort_unless($tenant->area_building_id === $building->id, 404);
    }

    /**
     * store / update に共通する列。
     *
     * ⚠ **store と update で列の並べ方を変えないこと。** 今日は任意列が全て nullable で
     *   既定 NULL なので `create(array_merge($validated, …))` でも挙動は同じだが、
     *   非 NULL 既定の列が 1 本増えた瞬間に**無音で割れる**。兄弟の
     *   AreaBuildingSurveyController::payload() と同じ形に揃えてある。
     * ⚠ 未送信キーは validated() に入らないので、null に落としたい列は明示的に埋める
     *   （任意項目が送られなかったとき旧値が残る事故を防ぐ。Bug #38）。
     *   フォームが描画している項目はフォームを正本にする。
     * ⚠ `status` だけ `?? null` を付けない。NOT NULL 列なので、`required` を外して
     *   キーが欠けたときは黙って null を書くのではなく**その場で落ちる**のが正しい。
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'floor'        => $validated['floor'] ?? null,
            'room_number'  => $validated['room_number'] ?? null,
            'name'         => $validated['name'] ?? null,
            'industry'     => $validated['industry'] ?? null,
            'status'       => $validated['status'],
            'confirmed_on' => $validated['confirmed_on'] ?? null,
            'moved_out_on' => $validated['moved_out_on'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ];
    }
}
