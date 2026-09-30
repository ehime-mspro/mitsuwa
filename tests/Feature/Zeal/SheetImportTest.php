<?php

namespace Tests\Feature\Zeal;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Models\ZealSimulation;
use App\Support\ZealSheetClient;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesZealSimulationSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * ZEAL 経営試算表の本部 Sheet 取込（`Zeal\SheetImportController`）のプレビュー → 反映の往復（この取込の初めての Feature テスト）。
 *
 * ⚠ 反映は確認画面 1 つにつき 1 回だけ（hidden の import_token）で、確認画面で見せた内容と同じものだけを書く
 *   （hidden の plan_digest。設計書 2026-09-28-import-double-submit-design.md §4.3）。
 *   直す前（2026-09-28 に実測）: 同じ確認画面から 2 回送ると、セルは同じ値を飛ばして書かないが、履歴（zeal_sheet_imports）は
 *   2 行が 4 行になり、「取り込みました (0 セル更新)」の成功の帯が出た。プレビューのあとで本部 Sheet が変わると、
 *   古い確認画面から、見せていない値（310,000）が書かれた。
 * ⚠ 本部 Sheet の読み先（ZealSheetClient::fetchCsv）は、決まった CSV を返す子クラスへコンテナごと差し替える
 *   （パーサ・経費の集約・buildApplyPlan は本物）。Http::preventStrayRequests() で、差し替えが効いていなければ外へ出ずに落ちる。
 */
class SheetImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSimulationSchema;
    use ParsesForms;
    use ChecksDoubleSubmit;

    private const YEAR_MONTH  = '2026-07';
    private const SALES_URL   = 'https://docs.google.com/spreadsheets/d/TEST-SALES/export?format=csv&gid=0';
    private const EXPENSE_URL = 'https://docs.google.com/spreadsheets/d/TEST-EXPENSE/export?format=csv&gid=0';

    /** 売上 Sheet（A）: 3 式とも整合。当月売上合計 304,638 */
    private const SALES_A = "項目,金額\n"
        . "当月日割売上金,200000\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,304638\n"
        . "ロイヤリティ額,9139\n"
        . "差し引き精算額,295499\n";

    /** 売上 Sheet（B）: プレビューのあとに本部が直した想定。当月売上合計 310,000 */
    private const SALES_B = "項目,金額\n"
        . "当月日割売上金,205362\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,310000\n"
        . "ロイヤリティ額,9300\n"
        . "差し引き精算額,300700\n";

    /** 売上 Sheet（A の書き込みに使わない行だけを直したもの）: 当月売上合計は 304,638 のまま */
    private const SALES_A_NOTE_ONLY = "項目,金額\n"
        . "当月日割売上金,200000\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,304638\n"
        . "ロイヤリティ額,9139\n"
        . "差し引き精算額,295499\n"
        . "備考,1\n";

    /** 経費 Sheet: 委託費 440,000・時間帯業務委託費 33,000・研修システム 16,500・WEB運用費 16,500・店舗備品費 5,500 */
    private const EXPENSE = "項目,金額(税込)\n"
        . "運営費,516053\n"
        . "店舗備品費,5500\n"
        . "総計,521553\n"
        . "\n"
        . "項目,納品月,品目,個数,金額(税込)\n"
        . "運営費,2026-07,店舗運営委託費,1,440000\n"
        . "運営費,2026-07,時間帯業務委託費,3,33000\n"
        . "運営費,2026-07,研修システム,1,16500\n"
        . "運営費,2026-07,WEB運用費,1,16500\n"
        . "運営費,2026-07,hacomono決済手数料,1,10053\n"
        . "店舗備品費,2026-07,トイレットペーパー,2,5500\n";

    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 §4.4） */
    private const USED_TOKEN = 'この確認画面からは反映できません（すでに送信したか、画面が古くなっています）。反映されたかは、この試算表で確かめられます。反映し直すときは、「本部 Sheet を取り込む」からもう一度プレビューしてください。';

    /** プレビューのあとで反映する内容が変わったときの案内（設計書 §4.3） */
    private const PLAN_CHANGED = 'プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。';

    /** 本部 Sheet の読み先の差し替え（URL => CSV） */
    private object $sheets;

    private User $user;

    private ZealSimulation $simulation;

    protected function setUp(): void
    {
        parent::setUp();

        // 差し替えが効いていなければ外へ出ずに落ちる
        Http::preventStrayRequests();

        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $this->seedZealSheetImportCategories();
        $this->seed(DepartmentSeeder::class);

        $this->sheets = new class extends ZealSheetClient
        {
            /** @var array<string, string> */
            public array $csv = [];

            public function fetchCsv(string $url): string
            {
                if (! array_key_exists($url, $this->csv)) {
                    throw new \RuntimeException("テストで用意していない URL: {$url}");
                }

                return $this->csv[$url];
            }
        };
        $this->sheets->csv = [self::SALES_URL => self::SALES_A, self::EXPENSE_URL => self::EXPENSE];
        $this->app->instance(ZealSheetClient::class, $this->sheets);

        // zeal 部門の管理者（role:executive,manager と department.access:zeal を通る）
        $this->user = User::factory()->create(['role' => UserRole::Manager->value, 'must_change_password' => false]);
        $this->user->departments()->attach(Department::where('code', 'zeal')->value('id'));

        $this->simulation = ZealSimulation::create([
            'fiscal_year'       => 2026,
            'name'              => '2026年度',
            'sales_sheet_url'   => self::SALES_URL,
            'expense_sheet_url' => self::EXPENSE_URL,
        ]);

        // いまのセル: 売上は「実績を反映」で入った値、委託費は固定額の既定値の想定。
        // 1 回目で UPDATE（売上・委託費）と INSERT（残り 4 項目）の両方を通す
        $this->setCell('revenue', 250000);
        $this->setCell('outsourcing', 400000);
    }

    // ================================================================
    // 往復
    // ================================================================

    public function test_applying_the_preview_writes_the_cells_and_the_history(): void
    {
        $landed = $this->apply($this->previewForm());

        $this->assertSame(302, $landed->getStatusCode());
        $this->assertSame(route('zeal.simulations.show', $this->simulation), $landed->headers->get('Location'));
        $this->assertSame('2026-07 の本部 Sheet を取り込みました (6 セル更新)。', session('success'));
        $this->assertSame(
            ['outsourcing' => 440000, 'revenue' => 304638, 'session_fee' => 33000, 'store_supplies' => 5500, 'training_system' => 16500, 'web_operation' => 16500],
            $this->cells()
        );
        $this->assertSame(['sales', 'expense'], DB::table('zeal_sheet_imports')->orderBy('id')->pluck('import_type')->all());
    }

    // ================================================================
    // 反映は確認画面 1 つにつき 1 回だけ
    // ================================================================

    public function test_sending_the_same_confirmation_twice_applies_once(): void
    {
        $form = $this->previewForm();
        $this->apply($form);
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count(), '1 回目で履歴が 2 行できていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->apply($form));

        // 鍵を先に使う（指紋を先に比べると、1 回目で値が書かれたあとなので「内容が変わりました」という別の理由で断る）
        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った（履歴が増える）');
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($second, route('zeal.simulations.show', $this->simulation), self::USED_TOKEN, $this->user);
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_applies_nothing(string|array|null $token): void
    {
        $form = $this->withField($this->previewForm(), 'import_token', $token);

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->apply($form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($response, route('zeal.simulations.show', $this->simulation), self::USED_TOKEN, $this->user);
    }

    public function test_previewing_again_issues_a_new_token(): void
    {
        $first  = $this->previewForm();
        $second = $this->previewForm();

        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->apply($second);
        $this->assertSame(304638, $this->cells()['revenue'], 'プレビューし直した確認画面の鍵で反映できない');
    }

    // ================================================================
    // 確認画面で見せた内容と同じものだけを書く（指紋。設計書 §4.3 の表）
    // ================================================================

    public function test_a_changed_amount_in_the_sheet_turns_the_confirmation_back(): void
    {
        $form = $this->previewForm();

        // プレビューのあとで、本部が売上 Sheet を直した（直す前は 310,000 が書かれた）
        $this->sheets->csv[self::SALES_URL] = self::SALES_B;

        $this->assertTurnedBack($form);
        $this->assertSame(250000, $this->cells()['revenue']);
    }

    public function test_a_cell_that_now_needs_writing_turns_the_confirmation_back(): void
    {
        // 研修システムはプレビューのとき本部 Sheet と同じ値（書かない行）
        $this->setCell('training_system', 16500);
        $form = $this->previewForm();

        // プレビューのあとで手で直した（書く行になった）
        $this->setCell('training_system', 15000);

        $this->assertTurnedBack($form);
        $this->assertSame(15000, $this->cells()['training_system']);
    }

    public function test_a_cell_that_no_longer_needs_writing_turns_the_confirmation_back(): void
    {
        $form = $this->previewForm();

        // プレビューのあとで、委託費を本部 Sheet と同じ値に手で直した（書く行でなくなった）
        $this->setCell('outsourcing', 440000);

        $this->assertTurnedBack($form);
    }

    public function test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back(): void
    {
        $firstTab  = $this->previewForm();
        $secondTab = $this->previewForm();

        $this->apply($firstTab);

        // 後のタブの鍵は使えるが、書く行がもう無い（順に届く 2 つのタブの重複もこれで止まる）
        $this->assertTurnedBack($secondTab);
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count());
    }

    public function test_a_changed_current_value_of_a_written_cell_still_applies(): void
    {
        $form = $this->previewForm();

        // 書く行（委託費）のいまの値だけが変わった（書く値 440,000 は同じ）
        $this->setCell('outsourcing', 410000);

        $this->apply($form);
        $this->assertSame(440000, $this->cells()['outsourcing'], 'プレビューで見せた値が書かれていない');
    }

    public function test_changes_that_do_not_touch_the_plan_still_apply_and_the_history_keeps_what_was_read(): void
    {
        $form = $this->previewForm();

        // 反映に関係の無いセル（賃料）と、本部 Sheet の書き込みに使わない行だけが変わった
        $this->setCell('rent', 180000);
        $this->sheets->csv[self::SALES_URL] = self::SALES_A_NOTE_ONLY;

        $this->apply($form);
        $this->assertSame(304638, $this->cells()['revenue']);
        // 履歴の raw_csv は、反映のときに読んだ中身で残す
        $this->assertSame(self::SALES_A_NOTE_ONLY, DB::table('zeal_sheet_imports')->where('import_type', 'sales')->value('raw_csv'));
    }

    /** @return array<string, array{0: string|list<string>|null}> [plan_digest に入れる値（null なら送らない）] */
    public static function unusableDigests(): array
    {
        return [
            '指紋が無い' => [null],
            '指紋が空'   => [''],
            '指紋が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableDigests')]
    public function test_a_confirmation_without_a_usable_digest_applies_nothing(string|array|null $digest): void
    {
        // 500 にならない（配列を hash_equals() に渡すと TypeError になるので、is_string で先に断る）
        $this->assertTurnedBack($this->withField($this->previewForm(), 'plan_digest', $digest));
    }

    /** @return array<string, array{0: string, 1: string}> [反映のときに読み直せない Sheet の URL, 画面での名前] */
    public static function sheetsThatCannotBeReadAgain(): array
    {
        return [
            '売上' => [self::SALES_URL, '売上'],
            '経費' => [self::EXPENSE_URL, '経費'],
        ];
    }

    /**
     * プレビューのあとで片方の Sheet だけを読み直せなかった（一時的な障害）: 「内容が変わりました（…値が変わっています）」
     * ではなく、読み直せなかったと断る。書き込みは 0（2026-09-28 の独立レビューで、事実と違う理由を出していたことを実測）。
     */
    #[DataProvider('sheetsThatCannotBeReadAgain')]
    public function test_a_sheet_that_cannot_be_read_again_turns_the_confirmation_back_with_that_reason(string $url, string $label): void
    {
        $form = $this->previewForm();

        // 反映のときだけ読めない（差し替えの fetchCsv() は、用意していない URL で例外を投げる）
        unset($this->sheets->csv[$url]);

        $cells = $this->cells();
        [$response, $writes] = $this->countingWrites(fn () => $this->apply($form));

        $this->assertSame(0, $writes, '読み直せなかったのに書き込みが走った');
        $this->assertSame($cells, $this->cells());
        $this->assertSame(0, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused(
            $response,
            route('zeal.simulations.show', $this->simulation),
            "プレビューのあとで{$label} Sheet を読み直せませんでした（テストで用意していない URL: {$url}）。時間をおいて、もう一度プレビューしてください。",
            $this->user
        );
    }

    /**
     * プレビューのときも読めなかった Sheet があるだけでは断らない（見せた内容と同じものを書く。設計書 §4.3）。
     * ⚠ 「URL があるのに読めない Sheet があれば断る」形にすると、片方の Sheet が壊れているあいだ、もう片方も反映できなくなる
     */
    public function test_a_sheet_that_could_not_be_read_at_the_preview_either_does_not_block_the_other(): void
    {
        unset($this->sheets->csv[self::EXPENSE_URL]);
        $form = $this->previewForm();

        $this->apply($form);

        $this->assertSame('2026-07 の本部 Sheet を取り込みました (1 セル更新)。', session('success'));
        $this->assertSame(304638, $this->cells()['revenue']);
        $this->assertSame(400000, $this->cells()['outsourcing'], '読めなかった経費の項目が書かれた');
        $this->assertSame(['sales'], DB::table('zeal_sheet_imports')->pluck('import_type')->all());
    }

    /**
     * URL が無い Sheet（プレビューのときも「未設定」）は、読み直せなかったとは言わない。内容が変わったときは今までどおりの案内
     */
    public function test_a_sheet_without_a_url_is_not_reported_as_unreadable(): void
    {
        $this->simulation->forceFill(['expense_sheet_url' => null])->save();
        $form = $this->previewForm();

        // プレビューのあとで、本部が売上 Sheet を直した
        $this->sheets->csv[self::SALES_URL] = self::SALES_B;

        $this->assertTurnedBack($form);
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview()->getContent();
        $action = route('zeal.simulations.sheet-import.apply', $this->simulation);

        $this->assertSubmitOnceForm($html, $action, 'submitOnce()', '反映しています…');

        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $form['fields']['plan_digest'] ?? '', '確定のフォームに指紋（plan_digest）が無い');
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 試算表の画面の月選択から「プレビューを表示」を押す（フォームが送るのは year_month だけ） */
    private function preview(): TestResponse
    {
        return $this->actingAs($this->user)
            ->post(route('zeal.simulations.sheet-import.preview', $this->simulation), ['year_month' => self::YEAR_MONTH])
            ->assertOk();
    }

    /** プレビューが描いた「試算表に反映する」のフォームを分解する */
    private function previewForm(): array
    {
        return $this->parseForm(
            $this->preview()->getContent(),
            'action="' . route('zeal.simulations.sheet-import.apply', $this->simulation) . '"'
        );
    }

    /** 確認画面から反映を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function apply(array $form): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('zeal.simulations.sheet-import.preview', $this->simulation))
            ->post($form['action'], $form['fields']);
    }

    /** フォームの 1 つの値を差し替える（null なら送らない） */
    private function withField(array $form, string $name, string|array|null $value): array
    {
        if ($value === null) {
            unset($form['fields'][$name]);
        } else {
            $form['fields'][$name] = $value;
        }

        return $form;
    }

    /** 反映が「内容が変わりました」で断られ、セルにも履歴にも何も書かないこと */
    private function assertTurnedBack(array $form): void
    {
        $cells = $this->cells();
        $history = DB::table('zeal_sheet_imports')->count();

        [$response, $writes] = $this->countingWrites(fn () => $this->apply($form));

        $this->assertSame(0, $writes, '断るのに書き込みが走った');
        $this->assertSame($cells, $this->cells());
        $this->assertSame($history, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($response, route('zeal.simulations.show', $this->simulation), self::PLAN_CHANGED, $this->user);
    }

    private function setCell(string $code, int $amount): void
    {
        DB::table('zeal_simulation_values')->updateOrInsert(
            [
                'simulation_id' => $this->simulation->id,
                'category_id'   => DB::table('zeal_simulation_categories')->where('code', $code)->value('id'),
                'year_month'    => self::YEAR_MONTH,
            ],
            ['amount' => $amount, 'is_manual_override' => 0, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** @return array<string, int> その月のセル（項目のコード => 金額。コードの順） */
    private function cells(): array
    {
        return DB::table('zeal_simulation_values as v')
            ->join('zeal_simulation_categories as c', 'c.id', '=', 'v.category_id')
            ->where('v.simulation_id', $this->simulation->id)
            ->where('v.year_month', self::YEAR_MONTH)
            ->orderBy('c.code')
            ->pluck('v.amount', 'c.code')
            ->map(fn ($amount) => (int) $amount)
            ->all();
    }
}
