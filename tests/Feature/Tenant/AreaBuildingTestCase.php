<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Tests\TestCase;

/**
 * 周辺ビル調査の Feature テスト共通土台。
 *
 * ⚠ ファイル名が *Test.php ではないので PHPUnit のテスト探索には引っかからない（意図どおり）。
 */
abstract class AreaBuildingTestCase extends TestCase
{
    private bool $departmentsSeeded = false;

    /**
     * tenant 部門所属のユーザー。department.access:tenant を通過させ、
     * 403 が role ゲート由来であることを保証する。
     */
    protected function actor(UserRole $role): User
    {
        // ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }

        $user = User::factory()->create([
            'role'                 => $role->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'tenant')->value('id'));

        return $user;
    }

    protected function executive(): User
    {
        return $this->actor(UserRole::Executive);
    }

    protected function manager(): User
    {
        return $this->actor(UserRole::Manager);
    }

    protected function staff(): User
    {
        return $this->actor(UserRole::Staff);
    }

    protected function makeBuilding(string $name, array $attributes = []): AreaBuilding
    {
        return AreaBuilding::create(array_merge(['name' => $name], $attributes));
    }

    protected function makeSurvey(
        AreaBuilding $building,
        string $month,
        int $operating,
        int $vacant,
        int $unknown = 0,
        array $extra = []
    ): AreaBuildingSurvey {
        return AreaBuildingSurvey::create(array_merge([
            'area_building_id' => $building->id,
            'surveyed_month'   => $month,
            'operating_count'  => $operating,
            'vacant_count'     => $vacant,
            'unknown_count'    => $unknown,
        ], $extra));
    }

    protected function makeTenant(AreaBuilding $building, array $attributes = []): AreaBuildingTenant
    {
        return AreaBuildingTenant::create(array_merge([
            'area_building_id' => $building->id,
            'status'           => 'operating',
        ], $attributes));
    }

    /** ページャに載った行のビル名（表示順のまま） */
    protected function listedNames(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->viewData('rows')->items())
            ->map(fn (array $row) => $row['building']->name)
            ->all();
    }

    // ============================================================
    // 描画されたフォームの往復（Bug #28: 呼び出し側と実体を対で固定する）
    // ============================================================

    /**
     * 画面が実際に描画したフォームを、ブラウザと同じように分解する。
     *
     * これが無いと、HTTP メソッド（`@method`）・送信先（`action`）・選択肢が**片側だけ**の
     * 状態になる。実測（2026-08-17）で、`@method('PUT')` や `@method('DELETE')` を消しても
     * `action` を store 側へ向けても、テストは全部緑のままだった。
     *
     * 使い方（返り値をそのまま送り返すのが「ブラウザと同じ」）:
     *   $form = $this->parseForm($html, 'action="' . route('...update', [$a, $b]) . '"');
     *   $this->post($form['action'], $form['fields']);   // _method で PUT / DELETE へ化ける
     *
     * ⚠ **$needle は `action="…"` まで含める形で渡すこと。** 素の URL で探すと
     *   destroy の URL（`…/surveys/1`）が編集リンク（`…/surveys/1/edit`）に前方一致し、
     *   その手前にある**別のフォーム**を掴む。誤用を黙って通さないよう、needle が
     *   開始タグの中にあることを下でアサートしている。
     *
     * ⚠ `@csrf` の欠落は Feature テストでは**原理的に挙動から検出できない**
     *   （VerifyCsrfToken が runningUnitTests() で素通りする）。描画された `_token` hidden の
     *   存在をアサートするのが唯一の手なので、fields に `_token` を残している。
     *
     * ⚠ **`<select>` の往復テストは「先頭 option 以外」の値で測ること。**
     *   下の selectedValue() は「`selected` が 1 つも無ければ**先頭 option**」を返す
     *   （ブラウザ挙動として正しい）。空の先頭 option を持たないセレクトで、たまたま
     *   先頭 case のデータを使うと、**正しい描画と壊れた描画が同じ値になり false-pass する**。
     *   実測（2026-08-17）: `AreaTenantStatus::cases()` の先頭は Operating なので、
     *   `tenants/_form.blade.php` から `$tenant?->status?->value ??` を落としても
     *   26 本全部が緑だった（同じ壊し方を `old('floor', …)` にすると赤になる。**select だけが例外**）。
     *   固定するときは Vacant / Unknown のような**先頭以外の case**で往復させる
     *   （`AreaBuildingTenantCrudTest::test_edit_form_preselects_the_stored_status`）。
     *
     * ⚠ **未チェックの checkbox は fields に入らない**（ブラウザと同じ）。よって
     *   `assertArrayNotHasKey('x', $form['fields'])` は「**チェック済みで出ている**」場合しか
     *   検出できず、「その画面に出してはいけない項目が出ていないこと」の証明にはならない。
     *   実測（2026-08-17）: 「保存して続けて登録」ブロックを edit 画面へ貼り付けても
     *   662 テスト全部が緑だった。**「無いこと」は生 HTML で見る**
     *   （`assertStringNotContainsString('name="keep_adding"', $html)`）。
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    protected function parseForm(string $html, string $needle): array
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "フォームが見つからない: {$needle}");

        $open = strrpos(substr($html, 0, $pos), '<form');
        $this->assertNotFalse($open, "{$needle} を含む <form> の開始タグが見つからない");

        $close = strpos($html, '</form>', $pos);
        $this->assertNotFalse($close, "{$needle} を含む <form> が閉じていない");

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        $this->assertStringContainsString(
            $needle,
            $openTag,
            "{$needle} が <form> の開始タグの中に無い。素の URL ではなく action=\"…\" 込みで指定すること"
        );

        $fields = [];

        preg_match_all('/<input\b[^>]*>/i', $form, $inputs);
        foreach ($inputs[0] as $tag) {
            $name = $this->htmlAttr($tag, 'name');
            $type = strtolower($this->htmlAttr($tag, 'type') ?? 'text');

            if ($name === null || in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                continue;
            }
            // 未チェックの checkbox / radio はブラウザも送らない
            if (in_array($type, ['checkbox', 'radio'], true) && ! preg_match('/\bchecked\b/i', $tag)) {
                continue;
            }

            $fields[$name] = $this->htmlAttr($tag, 'value') ?? '';
        }

        preg_match_all('/<textarea\b([^>]*)>(.*?)<\/textarea>/is', $form, $areas, PREG_SET_ORDER);
        foreach ($areas as $area) {
            $name = $this->htmlAttr('<textarea' . $area[1] . '>', 'name');
            if ($name !== null) {
                $fields[$name] = html_entity_decode($area[2], ENT_QUOTES, 'UTF-8');
            }
        }

        preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $form, $selects, PREG_SET_ORDER);
        foreach ($selects as $select) {
            $name = $this->htmlAttr('<select' . $select[1] . '>', 'name');
            if ($name === null) {
                continue;
            }
            $fields[$name] = $this->selectedValue($select[2]);
        }

        return [
            // 実効メソッド。@method('PUT') が消えれば POST に落ちるので、ここで差が出る
            'method' => strtoupper($fields['_method'] ?? $this->htmlAttr($openTag, 'method') ?? 'GET'),
            'action' => $this->htmlAttr($openTag, 'action') ?? '',
            'fields' => $fields,
        ];
    }

    /** ブラウザと同じく、selected な option（無ければ先頭）の value を送る */
    private function selectedValue(string $optionsHtml): string
    {
        preg_match_all('/<option\b[^>]*>/i', $optionsHtml, $options);

        foreach ($options[0] as $option) {
            if (preg_match('/\bselected\b/i', $option)) {
                return $this->htmlAttr($option, 'value') ?? '';
            }
        }

        return isset($options[0][0]) ? ($this->htmlAttr($options[0][0], 'value') ?? '') : '';
    }

    /**
     * タグから属性値を取り出す。
     * ⚠ `\bname=` だと `data-name=` にも当たる（`-` の直後は語境界）。
     *   語構成文字とハイフンの直後を除外する。
     */
    private function htmlAttr(string $tag, string $name): ?string
    {
        $pattern = '/(?<![\w-])' . preg_quote($name, '/') . '="([^"]*)"/i';

        return preg_match($pattern, $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }
}
