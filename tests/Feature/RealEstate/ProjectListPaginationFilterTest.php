<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\ReProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 分譲地一覧で「ステータス: 全て」を選んだままページ送りしても絞り込みが維持されること。
 *
 * `withQueryString()` は内部で Arr::query()（= http_build_query）を通すため、
 * 値が null のキーを丸ごと捨てる。一覧の「全て」は <option value=""> で、これは
 * ConvertEmptyStringsToNull ミドルウェアにより null になるので、2 ページ目のリンクから
 * status が消え、コントローラの既定 'active'（不成立・販売済を除く）に戻ってしまう。
 *
 * ⚠ 21 件以上ないとページ送り自体が出ないので再現しない。2026-07-28 に本番で
 *    total=21 のときに実測されたが、その後データが 16 件に減って再現しなくなっていた。
 *    件数に依存しないようテストで固定する。
 *
 * ⚠ Request::create() では ConvertEmptyStringsToNull が動かず '' のまま届くため
 *    この欠陥は再現しない（Bug #31）。必ず実 HTTP で叩くこと。
 */
class ProjectListPaginationFilterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** 1 ページあたりの件数（ProjectController::index の paginate(20) と揃える） */
    private const PER_PAGE = 20;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 既定フィルタ 'active' が除外する販売済・不成立を必ず含めて 2 ページ分作る。
     * 「全て」と既定とで件数が変わらないと、フィルタが飛んでもテストが緑になってしまう。
     */
    private function seedProjects(int $excluded, int $ongoing): void
    {
        $n = 0;
        foreach (
            array_merge(
                array_fill(0, $excluded, ProjectStatus::SoldOut->value),
                array_fill(0, $ongoing, ProjectStatus::Selling->value)
            ) as $status
        ) {
            $n++;
            ReProject::create([
                'project_code' => sprintf('RE-PRJ-%03d', $n),
                'project_name' => "分譲地{$n}",
                'status'       => $status,
                'address'      => '愛媛県松山市1-1-1',
                'created_by'   => 1,
            ]);
        }
    }

    /**
     * 「全て」でページ送りしても 2 ページ目が「全て」のままであること。
     *
     * ⚠ URL を自分で `?status=&page=2` と組み立てて叩いてはいけない。それだと
     *    ページリンクが壊れていても status が付いた状態で届くので必ず緑になる。
     *    1 ページ目を描画させ、**ページャが出したリンクを実際に辿る**こと。
     */
    public function test_all_status_filter_survives_pagination(): void
    {
        // 販売済 5 + 進行中 20 = 全 25 件（既定 'active' では 20 件 = 1 ページに収まる）
        $this->seedProjects(excluded: 5, ongoing: 20);

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get('/realestate/projects?status=');
        $page1->assertOk();
        $this->assertSame(25, $page1->viewData('projects')->total());

        // ページャ自身が生成した「次へ」リンクをそのまま辿る（＝画面のリンクと同じ）
        $nextUrl = $page1->viewData('projects')->nextPageUrl();
        $this->assertNotNull($nextUrl, 'ページ送りが出ていないと何も検証できていない');

        $page2 = $this->actingAs($user)->get($nextUrl);
        $page2->assertOk();

        $paginator = $page2->viewData('projects');

        $this->assertSame(
            25,
            $paginator->total(),
            '2 ページ目で「全て」が既定の active に戻っている（status がページリンクから落ちた）'
        );
        $this->assertSame(5, $paginator->count(), '25 件の 2 ページ目は 5 件');
    }

    /** ページリンクに status キーが残っていること（落ちると上のテストの前提が崩れる） */
    public function test_pagination_links_keep_the_empty_status_key(): void
    {
        $this->seedProjects(excluded: 5, ongoing: 20);

        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects?status=');

        $response->assertOk();

        $paginator = $response->viewData('projects');
        $this->assertTrue($paginator->hasPages(), 'ページ送りが出ていないと何も検証できていない');

        $this->assertStringContainsString(
            'status=',
            $paginator->nextPageUrl(),
            'ページリンクから status キーが消えている（withQueryString() が null を捨てている）'
        );
    }

    /** 既定（フィルタ未指定）は従来どおり進行中のみであること＝正規化で全件化していない */
    public function test_default_filter_still_excludes_sold_out(): void
    {
        $this->seedProjects(excluded: 5, ongoing: 20);

        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects');

        $response->assertOk();
        $this->assertSame(20, $response->viewData('projects')->total());
    }
}
