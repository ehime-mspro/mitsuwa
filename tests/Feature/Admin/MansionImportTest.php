<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\MsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesMansionSchema;
use Tests\TestCase;

/**
 * 賃貸マンション CSV 取込の振る舞い。
 *
 * ⚠ **`MansionImportController` は追加以来ノータッチ（1 コミット）で、
 *   振る舞いのテストが 1 本も無かった**（2026-08-18 時点で 1,489 行）。
 *   本番の `ms_*` が全テーブル 0 件なので誰も踏んでいないが、
 *   これから一括投入するときに初めて踏む。
 *
 * ⚠ **プレビューと確定は別の HTTP リクエスト。** 確定側は画面が持ち回った base64 から
 *   CSV を復元し、行バリデーションを最初からやり直す。よってテストも
 *   **画面が描画した hidden をそのまま送り返す**（Bug #47 の往復テスト）。
 *   URL を `assertSee` で見るだけでは配線の半分も押さえられない。
 */
class MansionImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プレビューを描画させる（確定はしない）。 */
    private function preview(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->post("/admin/mansion-import/{$tab}", [
            'csv_file' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF" . $csv),
        ]);
    }

    /**
     * プレビュー → 確定の往復。
     *
     * **画面が描画した `csv_data` hidden を抜き出して送り返す**ので、
     * その hidden が消えたり名前が変わったりすれば赤くなる。
     * 自前で base64 を組み立ててはいけない（画面が壊れていても緑になる）。
     */
    private function confirm(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $matched = preg_match(
            '/<input type="hidden" name="csv_data" value="([^"]*)">/',
            $preview->getContent(),
            $m
        );

        $this->assertSame(1, $matched, "プレビュー画面に csv_data hidden が無い（tab={$tab}）");

        return $this->actingAs($this->executive())->post("/admin/mansion-import/{$tab}", [
            'confirmed' => '1',
            'csv_data'  => $m[1],
        ]);
    }

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    public function test_property_round_trip_creates_the_row(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "ミツワレジデンス,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,メモ\n";

        $response = $this->confirm('property', $csv);

        $response->assertRedirect('/admin/mansion-import?selected_tab=property');
        $response->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $property = MsProperty::sole();
        $this->assertSame('ミツワレジデンス', $property->property_name);
        $this->assertSame('MS-001', $property->property_code);
        $this->assertSame('self_owned', $property->ownership_type->value);
        $this->assertSame('愛媛県松山市一番町1-1', $property->address);
        $this->assertSame(20, $property->total_units);
        $this->assertSame(5, $property->total_floors);
        $this->assertSame('2010-04', $property->built_year_month);
    }
}
