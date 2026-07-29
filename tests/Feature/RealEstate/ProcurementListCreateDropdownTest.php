<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Models\ZoningType;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧から分譲地を新規登録する導線の検証。
 *
 * - 一覧ヘッダーの「新規登録」ドロップダウンに 2 経路が出ること
 * - 分譲地 新規登録画面が正しく開けること（zoning_types 由来の 500 が起きないこと）
 * - 分譲地 新規登録画面が ?from=procurements のときだけ仕入れ案件一覧へ戻ること
 *
 * ⚠ アサーションの作り方に注意（false-pass しやすい）:
 *   サイドバーが /realestate/procurements と /realestate/projects の href を
 *   それぞれ複数回描画するため、素の assertSee(route(...)) や出現回数カウントは
 *   当てにならない。パンくずは完全な <a ...>ラベル</a> 文字列で、
 *   キャンセルは「キャンセル というラベルを持つ <a> の href」を正規表現で見る。
 */
class ProcurementListCreateDropdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 部署マスタは migration では投入されないので seeder で入れる（staff の所属付けに要る）
        $this->seed(DepartmentSeeder::class);
        // 用途地域の <option> 生成経路を空振りさせないため 1 件だけ入れる
        ZoningType::create(['name' => '第一種住居地域', 'sort_order' => 5]);
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 一般担当。department.access:realestate を通すため realestate に所属させる */
    private function staff(): User
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'realestate')->value('id'));

        return $user;
    }

    public function test_list_shows_both_create_links(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // URL そのもので見る。?from=procurements の有無まで含めて一意に判定できる
        $response->assertSee(route('realestate.procurements.create'), false);
        $response->assertSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }

    public function test_staff_sees_no_create_links(): void
    {
        $response = $this->actingAs($this->staff())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertDontSee(route('realestate.procurements.create'), false);
        $response->assertDontSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }

    /**
     * 分譲地 新規登録画面がそもそも開けること。
     *
     * ⚠ zoning_types は本番では raw SQL DDL 管理でマイグレーションに無く、
     *    CreatesRealEstateSchema にも入っていなかった（この画面を叩く既存テストが
     *    1 本も無かったため露見していなかった）。ProjectController::create() は
     *    ZoningType を引くので、trait 側で表を作らないとここで落ちる。
     */
    public function test_project_create_page_opens(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects/create');

        $response->assertOk();
        $response->assertSee('分譲地 新規登録');
        $response->assertSee('<option value="第一種住居地域"', false);
    }

    /**
     * ?from=procurements のとき、パンくずとキャンセルの両方が仕入れ案件一覧を指すこと。
     *
     * ⚠ 単に assertSee(route('realestate.procurements.index')) では駄目。
     *    サイドバーが同じ URL を 3 箇所描画しているので、実装が何もされていなくても緑になる。
     *    パンくずは完全な <a ...>ラベル</a>、キャンセルは「キャンセル というラベルを持つ
     *    <a> の href」を正規表現で見る。
     */
    public function test_project_create_from_procurements_points_back_to_procurement_list(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects/create?from=procurements');

        $response->assertOk();

        // コントローラの判定結果そのものを固定する
        $response->assertViewHas('backUrl', route('realestate.procurements.index'));
        $response->assertViewHas('backLabel', '仕入れ案件一覧');

        // パンくずの中間リンク（URL とラベルを同時に固定）
        $response->assertSee(
            '<a href="' . route('realestate.procurements.index') . '" class="hover:text-emerald-600 transition-colors">仕入れ案件一覧</a>',
            false
        );

        // キャンセルボタン（x-form-actions が描画する「キャンセル」ラベルの <a>）
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.procurements.index')),
            $response->getContent(),
            'キャンセルボタンが仕入れ案件一覧を指していること'
        );
        $this->assertDoesNotMatchRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent(),
            'キャンセルボタンが分譲地一覧を指したまま残っていないこと'
        );
    }

    /** パラメータ無しなら従来どおり分譲地一覧（既存挙動の回帰） */
    public function test_project_create_without_from_points_back_to_project_list(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects/create');

        $response->assertOk();
        $response->assertViewHas('backUrl', route('realestate.projects.index'));
        $response->assertViewHas('backLabel', '分譲地一覧');
        $response->assertSee(
            '<a href="' . route('realestate.projects.index') . '" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>',
            false
        );
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent()
        );
    }

    /** 未知の from はホワイトリストに落ちて分譲地一覧に戻る */
    public function test_unknown_from_value_falls_back_to_project_list(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects/create?from=housing');

        $response->assertOk();
        $response->assertViewHas('backUrl', route('realestate.projects.index'));
        $response->assertViewHas('backLabel', '分譲地一覧');
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            $this->cancelLinkPattern(route('realestate.procurements.index')),
            $response->getContent()
        );
    }

    /**
     * 「キャンセル」というラベルを持つ <a> の href が $url であることを見る正規表現。
     *
     * x-form-actions は href の直後に改行 + style/onmouseover 属性を並べるので、
     * 属性部分は [^>]* で読み飛ばす（属性値に > は含まれないことを実測済み）。
     * 意匠（inline style）に依存しないので、ボタンを再スタイルしても壊れない。
     */
    private function cancelLinkPattern(string $url): string
    {
        return '/<a href="' . preg_quote($url, '/') . '"[^>]*>\s*キャンセル\s*<\/a>/u';
    }
}
