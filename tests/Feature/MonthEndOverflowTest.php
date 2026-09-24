<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\ZealMember;
use App\Models\ZealSimulationCategory;
use App\Support\JapanTime;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesZealSchema;
use Tests\Concerns\CreatesZealSimulationSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 「前月」「来月」「過去 18 か月」を、今日が月末でも正しく求めること。
 *
 * Carbon の subMonth() / addMonth() / subMonths($i) は、移った先の月にその日が無いと翌月へ溢れる
 * （3/31 の 1 か月前 = 2/31 → 3/3、1 か月後 = 4/31 → 5/1）。直し方は「月初へ寄せてから足し引きする」
 * （前例: Zeal\DashboardController の月次グラフの起点）。
 *
 * ⚠ 今日を 2026-03-31 に固定するのが load-bearing。3/31 は前月（2 月）にも来月（4 月）にも同じ日が無く、
 *   過去 18 か月を並べても 7 回溢れる（前月・来月・過去 18 か月の 3 形とも溢れる日）。
 *   今日を月の途中（3/15 など）にすると前月も来月も、前月に同じ日がある月末（8/31 の前月 = 7/31 など）に
 *   すると前月が、正しい順序と誤った順序で同じ値になり、**修正を元に戻してもその部分のテストは緑のまま**になる
 *   （2026-09-04 に工程表ボードで実測済みの罠。ScheduleBoardTest の初期スクロールのテストを参照）。
 *   各テストの先頭で assertTodayOverflows() がこれを確かめる（日付を差し替えた瞬間に落ちる）。
 *
 * ⚠ 期待値は文字列・数値のリテラルで書く。Carbon で組み立てると実装と同じ式になり、
 *   実装を壊しても緑になる（同義反復）。
 */
class MonthEndOverflowTest extends TestCase
{
    use CreatesZealSchema;
    use CreatesZealSimulationSchema;
    use ParsesForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-03-31 03:00:00', 'UTC')); // 日本時間 3/31 12:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * テナントダッシュボードのビル別カードが、2 月を「前月」としてラベルと集計の両方を出す。
     *
     * ラベル（tenant() の previousMonthLabel）と集計（aggregateBuildingStats()）は同じ前月を
     * 別々に計算している。片方だけ直すとラベルと数字が食い違うので、1 本で両方を見る。
     * 同じ画面の 3 つ目の前月が、全体カードの実績期間（buildProjectionLabels() の「5〜2月」）。
     * 起点が元から月初なので今は安全だが、3 つが揃っていることをこの 1 本で見る（Bug #41）。
     *
     * 契約（前月 = 2 月なら K1 だけが入る）:
     *   K1 区画 A 1/10 契約・3/15 解約 家賃 100,000 円 → 2 月は有効・2 月末も入居中
     *   K2 区画 B 3/5 契約 家賃 30,000 円  → 2 月末より後の契約
     *   K3 区画 C 3/10 契約 家賃 20,000 円 → 2 月末より後の契約
     * 誤った順序（3/31 → 3/3 ＝ 3 月）だと、収入 150,000 円・入居率 66.7%（K1 は 3 月末より前に解約）になる。
     */
    public function test_the_tenant_dashboard_labels_and_counts_february_as_last_month(): void
    {
        $this->assertTodayOverflows();

        $customer = Customer::create(['code' => 'CU-MEO', 'name' => '月末テスト商事', 'customer_type' => 'corporation']);
        $building = Property::create([
            'code' => 'T-MEO-1', 'name' => '月末テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'operation_status' => 'active', 'address' => '愛媛県松山市',
        ]);

        $plans = [
            // [号室, 区画の状態, 契約番号, 契約日, 解約日, 契約の状態, 家賃]
            ['A', 'vacant', 'C-MEO-K1', '2026-01-10', '2026-03-15', 'terminated', 100000],
            ['B', 'occupied', 'C-MEO-K2', '2026-03-05', null, 'active', 30000],
            ['C', 'occupied', 'C-MEO-K3', '2026-03-10', null, 'active', 20000],
        ];
        foreach ($plans as [$room, $unitStatus, $number, $signedOn, $endedOn, $status, $rent]) {
            $unit = Unit::create([
                'property_id' => $building->id,
                'floor' => 1,
                'room_number' => $room,
                'display_name' => Unit::generateDisplayName(1, $room),
                'status' => $unitStatus,
            ]);
            Contract::create([
                'contract_number' => $number,
                'department' => 'tenant',
                'property_id' => $building->id,
                'unit_id' => $unit->id,
                'customer_id' => $customer->id,
                'status' => $status,
                'contract_date' => $signedOn,
                'rent_start_date' => $signedOn,
                'contract_end_date' => $endedOn,
                'rent' => $rent,
            ]);
        }

        $response = $this->actingAs($this->user(UserRole::Manager))->get('/dashboard/tenant');

        $response->assertOk();
        $this->assertSame('2月実績', $response->viewData('previousMonthLabel'), 'ラベルが前月（2 月）になっていない');
        $response->assertSee('<span class="section-label">2月実績</span>', false);
        $this->assertSame(
            [['id' => $building->id, 'name' => '月末テストビル', 'monthly_income' => 100000, 'occupancy_rate' => 33.3]],
            $response->viewData('buildings')->all(),
            '集計が前月（2 月）になっていない（3 月を集計すると 150,000 円・66.7%）'
        );
        $this->assertSame('5〜2月', $response->viewData('actualLabel'), '全体カードの実績期間の終わりが前月（2 月）になっていない');
    }

    /**
     * ZEAL ダッシュボードの「先月比」が 2 月と比べる。
     *
     * 会員: 入会 2 月 ×2・3 月 ×1 ／ 退会 2 月 ×2・3 月 ×1
     *   → 今月の入会 1・先月 2・差 -1 ／ 今月の退会 1・先月 2・差 -1
     * 誤った順序（3/31 → 3/3 ＝ 3 月）だと先月も 3 月を数え、先月 1・差 0 になる。
     *
     * ⚠ 画面の「〇年〇月〇日 時点」は $now をそのまま出す。前月を copy() せずに $now から求めると
     *   $now 自体が書き換わって「2026年2月1日」と出るので、併せて見る。
     */
    public function test_the_zeal_dashboard_compares_this_month_with_february(): void
    {
        $this->assertTodayOverflows();
        $this->createZealSchema();
        $this->seed(DepartmentSeeder::class);
        $this->pointGymInquiriesAtMemory();
        $actor = $this->zealUser();

        $members = [
            // [入会日, 退会日]
            ['2026-02-10', null],
            ['2026-02-20', null],
            ['2026-03-05', null],
            ['2025-06-01', '2026-02-15'],
            ['2025-06-01', '2026-02-25'],
            ['2025-06-01', '2026-03-10'],
        ];
        foreach ($members as $i => [$joinedOn, $withdrewOn]) {
            ZealMember::create([
                'store_id' => 1,
                'name' => '月末テスト会員' . ($i + 1),
                'joined_on' => $joinedOn,
                'withdrew_on' => $withdrewOn,
                'created_by' => $actor->id,
            ]);
        }

        $response = $this->actingAs($actor)->get('/zeal');

        $response->assertOk();
        $this->assertSame(0, $response->viewData('trialCount'), '体験予約の件数が向け直した先（空の表）の 0 になっていない（null なら向け直しが効かず、接続の失敗が握りつぶされた）');
        $this->assertSame(1, $response->viewData('joinedThisMonth'), '今月（3 月）の入会');
        $this->assertSame(2, $response->viewData('joinedLastMonth'), '先月（2 月）の入会になっていない');
        $this->assertSame(-1, $response->viewData('joinDiff'), '入会の先月比');
        $this->assertSame(1, $response->viewData('withdrewThisMonth'), '今月（3 月）の退会');
        $this->assertSame(2, $response->viewData('withdrewLastMonth'), '先月（2 月）の退会になっていない');
        $this->assertSame(-1, $response->viewData('withdrawDiff'), '退会の先月比');
        $response->assertSee('2026年3月31日 時点');
    }

    /**
     * 経営試算表の項目マスタの編集画面で、「適用開始月」の既定が来月（2026-04）になる。
     *
     * 誤った順序（3/31 → 4/31 → 5/1）だと 2026-05 になり、既定のまま保存すると
     * 4 月のセルに新しい金額が反映されない。
     */
    public function test_the_category_form_defaults_the_apply_from_month_to_april(): void
    {
        $this->assertTodayOverflows();
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $rent = ZealSimulationCategory::where('code', 'rent')->firstOrFail(); // 計算タイプが「固定額」

        $response = $this->actingAs($this->user(UserRole::Executive))
            ->get(route('admin.master.zeal-simulation-categories.edit', $rent));

        $response->assertOk();
        $form = $this->parseForm(
            $response->getContent(),
            'action="' . route('admin.master.zeal-simulation-categories.update', $rent) . '"'
        );
        $this->assertSame('2026-04', $form['fields']['apply_from_month'] ?? null, '既定の適用開始月が来月（4 月）になっていない');
    }

    /**
     * ZEAL 体験予約一覧の月の絞り込みが、当月から過去へ 18 か月を 1 か月ずつ並べる。
     *
     * 誤った順序（3/31 から毎回 subMonths($i)）だと、18 か月のうち重複 7 件・欠落 7 件（出る月は 11 種）になる。
     *
     * value とラベルは別の式で作っているので両方見る（ラベルを日の無い createFromFormat('Y-m', …) に変えると、
     * 3/31 には 2 月が「2026年3月」と出る）。
     */
    public function test_the_inquiry_month_filter_lists_18_consecutive_months(): void
    {
        $this->assertTodayOverflows();
        $this->seed(DepartmentSeeder::class);
        $this->pointGymInquiriesAtMemory();

        $html = $this->actingAs($this->zealUser())->get('/zeal/inquiries')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match_all('/<select\b[^>]*\sname="month"[^>]*>(.*?)<\/select>/s', $html, $selects),
            '月の <select> がちょうど 1 つ見つからない'
        );
        preg_match_all('/<option\b[^>]*\svalue="([^"]*)"/', $selects[1][0], $values);
        preg_match_all('/<option\b[^>]*>\s*([^<]*?)\s*<\/option>/u', $selects[1][0], $labels);

        $this->assertSame(
            [
                '',
                '2026-03', '2026-02', '2026-01', '2025-12', '2025-11', '2025-10', '2025-09', '2025-08', '2025-07',
                '2025-06', '2025-05', '2025-04', '2025-03', '2025-02', '2025-01', '2024-12', '2024-11', '2024-10',
            ],
            $values[1],
            '月の選択肢が当月から 18 か月連続になっていない（先頭の空は「月: すべて」）'
        );
        $this->assertSame(
            [
                '月: すべて',
                '2026年3月', '2026年2月', '2026年1月', '2025年12月', '2025年11月', '2025年10月', '2025年9月', '2025年8月', '2025年7月',
                '2025年6月', '2025年5月', '2025年4月', '2025年3月', '2025年2月', '2025年1月', '2024年12月', '2024年11月', '2024年10月',
            ],
            $labels[1],
            '月の選択肢のラベルが当月から 18 か月連続になっていない（ラベルは value と別の式で作っている）'
        );
    }

    /**
     * 固定した「今日」が、前月でも来月でも溢れる日であることを確かめる（各テストの先頭で呼ぶ）。
     *
     * ⚠ 前月か来月の片方でも溢れない日（3/15 は両方・8/31 は前月・7/31 は来月）に差し替えると、
     *   正しい順序と誤った順序が同じ値になり、修正を元に戻してもその部分のテストは緑のままになる。日付を差し替えた瞬間にここで落として気づけるようにする
     *   （前例: ScheduleBoardTest::test_the_initial_scroll_puts_the_first_day_of_last_month_at_the_left_edge）。
     */
    private function assertTodayOverflows(): void
    {
        $today = JapanTime::today();

        $this->assertNotSame(
            $today->copy()->startOfMonth()->subMonth()->format('Y-m'),
            $today->copy()->subMonth()->format('Y-m'),
            'この「今日」では前月の求め方 2 通りが同じ値になる＝月初へ寄せる修正を戻しても前月のテストが緑のまま（前月に同じ日が無い月末にすること）'
        );
        $this->assertNotSame(
            $today->copy()->startOfMonth()->addMonth()->format('Y-m'),
            $today->copy()->addMonth()->format('Y-m'),
            'この「今日」では来月の求め方 2 通りが同じ値になる＝月初へ寄せる修正を戻しても来月のテストが緑のまま（来月に同じ日が無い月末にすること）'
        );
    }

    /**
     * 体験予約（GymInquiry・'zeal' 接続）を SQLite のメモリ DB へ向け直し、一覧の問い合わせが使う列だけの表を作る。
     *
     * GymInquiry は外部 DB（本番は MySQL）を読む。ダッシュボードは件数を try/catch で、一覧は paginate で読む。
     * ⚠ 向け直さないと、テストが手元の MySQL（127.0.0.1:3306）へ接続しに行く
     *   （ダッシュボードは例外を握りつぶすので落ちずに通ってしまう。ダッシュボードのテストが
     *   trialCount = 0 を見て、向け直しが効いたことを確かめている）。
     * ⚠ gym_inquiries の正本の DDL はリポジトリに無い（外部の同期側が持つ）。ここに作るのは
     *   一覧の絞り込み・並び替えが使う列だけ。
     */
    private function pointGymInquiriesAtMemory(): void
    {
        config(['database.connections.zeal' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('zeal');
        Schema::connection('zeal')->create('gym_inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->nullable();
            $t->string('status', 30)->nullable();
            $t->date('inquiry_date')->nullable();
        });
    }

    /** ロールだけ決めた利用者（テナントダッシュボードは全ロール・項目マスタは経営層だけで、どちらも部署を問わない） */
    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role->value,
            'must_change_password' => false,
        ]);
    }

    /** zeal 部署に属する経営層（SimulationValidationFeedbackTest::actor() と同じ作り方） */
    private function zealUser(): User
    {
        $user = $this->user(UserRole::Executive);
        $user->departments()->attach(Department::where('code', 'zeal')->value('id'));

        return $user;
    }
}
