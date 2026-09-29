<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの契約・過去契約の CSV 取込を、上げ直しても二重にしない（設計書 2026-09-29-contract-reimport-design.md）。
 *
 * ⚠ 直す前（2026-09-29 に試作で実測）: 同じ CSV を上げ直すと、契約・過去契約とも同じ契約がもう一度入った
 *   （契約タブは「既にアクティブな契約」の警告だけ、過去契約は契約中の契約と重ならなければ何も出なかった）。
 * ⚠ 見分けのキーは 区画・顧客（テナント名が空欄なら顧客の無い契約）・契約日（設計書 §4.2）。
 *   賃料・状態・解約日・備考は使わない。削除した契約とは突き合わせない。テナントの 2 タブは同じキーで照合する。
 * ⚠ どれも、プレビューが描いた確定のフォームを分解してそのまま送り返す往復（SubmitsImportPreview。Bug #47 / #54 ②）。
 *   スキップの理由は「行N: …」の全文で見て、役割（viewData）と表示（画面の文字）を別々に見る（Bug #43 / #54 ④）。
 * ⚠ 「入る」を見るテスト（見分けのキーが 1 つ違う・削除した契約・まだ無い顧客）は、直す前のコードでも緑になる。
 *   照合が広すぎる書き換え（キーの要素を外す・削除済みも含める・まだ無い顧客も照合する）を捕まえる役
 *   （計画 docs/superpowers/plans/2026-09-29-contract-reimport.md の変異テストで赤を確かめる）。
 */
class TenantContractReimportTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';
    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';
    private const PROPERTY_HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private const CONTRACT_ROW_1 = '再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 松山支店,';
    private const CONTRACT_ROW_2 = '再取込ビル,2,A,別商事,2026-05-01,2026-05-01,100000,,,,,,';
    private const PAST_ROW_1 = '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,期間満了で解約';
    private const PAST_ROW_2 = '再取込ビル,2,A,別商事,2019-04-01,2019-04-01,2021-03-31,80000,,,,,,';

    /** CONTRACT_ROW_1 と同じ契約のスキップの理由（%s は登録済みの契約番号） */
    private const CONTRACT_SKIP = '区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 再取込商事）は既に登録済み（%s）のためスキップ';

    /** PAST_ROW_1 と同じ契約のスキップの理由（%s は登録済みの契約番号） */
    private const PAST_SKIP = '区画「再取込ビル 1A」の契約（契約日 2020-04-01・顧客 再取込商事）は既に登録済み（%s）のためスキップ';

    /** 契約・過去契約のタブの説明に足した 1 行（2 タブで同じ文） */
    private const TAB_NOTE = '<li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>';

    private Property $property;

    private Unit $unit1A;

    private Unit $unit2A;

    private Customer $customer;

    private Customer $other;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::create([
            'code' => 'T-RE-1', 'name' => '再取込ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        $this->unit1A = $this->unit(1, 'A');
        $this->unit2A = $this->unit(2, 'A');
        $this->customer = Customer::create(['code' => 'CU-RE-1', 'name' => '再取込商事', 'customer_type' => 'corporation']);
        $this->other = Customer::create(['code' => 'CU-RE-2', 'name' => '別商事', 'customer_type' => 'corporation']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    // ================================================================
    // 1・2: 同じ CSV を上げ直す／行を足した CSV を上げ直す
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [タブ, 2 行の CSV] */
    public static function twoRowCsvs(): array
    {
        return [
            '契約'     => ['contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n" . self::CONTRACT_ROW_2 . "\n"],
            '過去契約' => ['past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n" . self::PAST_ROW_2 . "\n"],
        ];
    }

    #[DataProvider('twoRowCsvs')]
    public function test_uploading_the_same_csv_again_skips_every_row(string $tab, string $csv): void
    {
        $this->confirm($tab, $csv)->assertRedirect(route('admin.tenant-import', ['tab' => $tab]));
        $this->assertSame(2, Contract::count(), '1 回目で 2 件が入っていない（測定が無効）');

        $this->assertPreviewSkipsEveryRow($tab, $csv, 2);
        $this->assertSame(2, Contract::count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    public static function addedRowCases(): array
    {
        return [
            '契約' => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, self::CONTRACT_ROW_2, self::CONTRACT_SKIP, '契約インポート完了: 1件を登録しました'],
            '過去契約' => ['past-contract', self::PAST_HEADER, self::PAST_ROW_1, self::PAST_ROW_2, self::PAST_SKIP, '過去契約インポート完了: 契約 1件を登録'],
        ];
    }

    #[DataProvider('addedRowCases')]
    public function test_uploading_a_csv_with_an_added_row_imports_only_the_new_row(
        string $tab, string $header, string $first, string $added, string $skipFormat, string $success
    ): void {
        $this->confirm($tab, "{$header}\n{$first}\n");
        $registered = Contract::sole();

        $csv = "{$header}\n{$first}\n{$added}\n";
        $preview = $this->preview($tab, $csv)->assertOk();
        $html = $preview->getContent();
        $message = sprintf($skipFormat, $registered->contract_number);

        // 役割: 1 行目はスキップ（エラーではない）・足した行は取り込む
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        // 表示: 灰色の一覧に全文・件数のチップ・ボタンの下の注記（Bug #43: 全文で見る）
        $this->assertStringContainsString('行2: ' . $message, $html);
        $this->assertStringContainsString('スキップ: <strong>1</strong> 件', $html);
        $this->assertStringContainsString('※ 既存データ（1件）はスキップされます', $html);

        $this->confirm($tab, $csv)
            ->assertRedirect(route('admin.tenant-import', ['tab' => $tab]))
            ->assertSessionHas('success', $success);
        $this->assertSame(2, Contract::count());
    }

    // ================================================================
    // 3・4: 見分けのキーが 1 つ違えば入る／キー以外が違ってもスキップ
    // ================================================================

    /** @return array<string, array{0: string}> [CSV の行。登録済みは 1A・再取込商事・2026-04-01] */
    public static function contractRowsWithAnotherKey(): array
    {
        return [
            '区画が違う'   => ['再取込ビル,2,A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客が違う'   => ['再取込ビル,1,A,別商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客が空欄'   => ['再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,'],
            '契約日が違う' => ['再取込ビル,1,A,再取込商事,2026-04-02,2026-04-02,95000,,,,,,'],
        ];
    }

    #[DataProvider('contractRowsWithAnotherKey')]
    public function test_a_contract_row_that_differs_in_one_key_element_is_imported(string $row): void
    {
        $this->registered($this->unit1A, $this->customer, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [CSV の行。登録済みは 1A・再取込商事・2020-04-01] */
    public static function pastRowsWithAnotherKey(): array
    {
        return [
            '区画が違う'   => ['再取込ビル,2,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '顧客が違う'   => ['再取込ビル,1,A,別商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '契約日が違う' => ['再取込ビル,1,A,再取込商事,2020-04-02,2020-04-02,2023-03-31,90000,,,,,,'],
        ];
    }

    #[DataProvider('pastRowsWithAnotherKey')]
    public function test_a_past_contract_row_that_differs_in_one_key_element_is_imported(string $row): void
    {
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_row_with_a_tenant_name_is_not_matched_to_a_contract_without_a_customer(): void
    {
        $this->registered($this->unit1A, null, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '顧客のいる行を「顧客の無い契約」と取り違えてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer(): void
    {
        $registered = $this->registered($this->unit1A, null, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,\n")->assertOk();

        $message = "区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 空欄）は既に登録済み（{$registered->contract_number}）のためスキップ";
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertStringContainsString('行2: ' . $message, $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: bool}> [CSV の行, 取り込んだあとで解約したか] */
    public static function contractRowsWithTheSameKey(): array
    {
        return [
            '家賃を改定した'   => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,100000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '備考が違う'       => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 松山支店,メモを足した', false],
            '屋号が違う'       => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 本店,', false],
            '賃料開始日が違う' => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-05-01,95000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '日付の書き方が違う'       => ['再取込ビル,1,A,再取込商事,2026/4/1,2026/4/1,95000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '取り込んだあとで解約した' => [self::CONTRACT_ROW_1, true],
        ];
    }

    #[DataProvider('contractRowsWithTheSameKey')]
    public function test_a_contract_row_that_differs_only_outside_the_key_is_skipped(string $row, bool $terminate): void
    {
        $this->confirm('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n");
        $registered = Contract::sole();
        if ($terminate) {
            $registered->update(['status' => 'terminated', 'contract_end_date' => '2026-08-31']);
        }

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [CSV の行。登録済みは PAST_ROW_1 を取り込んだもの] */
    public static function pastRowsWithTheSameKey(): array
    {
        return [
            '家賃が違う'   => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,100000,,,,,,期間満了で解約'],
            '解約日が違う' => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-06-30,90000,,,,,,期間満了で解約'],
            '備考が違う'   => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '日付の書き方が違う' => ['再取込ビル,1,A,再取込商事,2020/4/1,2020/4/1,2023/3/31,90000,,,,,,期間満了で解約'],
        ];
    }

    #[DataProvider('pastRowsWithTheSameKey')]
    public function test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped(string $row): void
    {
        $this->confirm('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n");
        $registered = Contract::sole();

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PAST_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    public function test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again(): void
    {
        // 顧客の無い契約（テナント名が空欄）を取り込み、同じ CSV を上げ直す（取込が customer_id に null を書き、照合も null どうしで見る）
        $csv = self::CONTRACT_HEADER . "\n再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,\n";
        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');
        $registered = Contract::sole();
        $this->assertNull($registered->customer_id);

        $preview = $this->assertPreviewSkipsEveryRow('contract', $csv, 1);

        $this->assertSame(
            [['row' => 2, 'message' => "区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 空欄）は既に登録済み（{$registered->contract_number}）のためスキップ"]],
            $preview->viewData('skippedRows')
        );
    }

    public function test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again(): void
    {
        // データ移行でよくある形: 1 回目の取込が顧客を自動作成し、上げ直したときにはその顧客が登録済みになっている
        $csv = self::PAST_HEADER . "\n再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";
        $this->confirm('past-contract', $csv)->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録、顧客 1件を自動作成');
        $registered = Contract::sole();

        $preview = $this->assertPreviewSkipsEveryRow('past-contract', $csv, 1);

        $this->assertSame(
            [['row' => 2, 'message' => "区画「再取込ビル 1A」の契約（契約日 2020-04-01・顧客 新しい商事）は既に登録済み（{$registered->contract_number}）のためスキップ"]],
            $preview->viewData('skippedRows')
        );
        $this->assertSame('過去契約 0件を新規作成', $preview->viewData('summary'), '登録済みの行の顧客を、自動作成の予定に数えた');
        $this->assertSame(1, Customer::where('name', '新しい商事')->count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>, 5: string}> */
    public static function badDateOnRegisteredRowCases(): array
    {
        return [
            '契約: 賃料開始日' => [
                'contract', self::CONTRACT_HEADER, '再取込ビル,1,A,再取込商事,2026-04-01,2026-02-30,95000,,,,,,',
                '2026-04-01', [], '賃料開始日「2026-02-30」の形式が不正です',
            ],
            '過去契約: 解約日' => [
                'past-contract', self::PAST_HEADER, '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-02-30,90000,,,,,,',
                '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], '解約日「2023-02-30」の形式が不正です（YYYY-MM-DD）',
            ],
        ];
    }

    #[DataProvider('badDateOnRegisteredRowCases')]
    public function test_a_registered_row_with_a_bad_date_is_an_error_not_skipped(
        string $tab, string $header, string $row, string $date, array $extra, string $error
    ): void {
        // 日付の検査は照合の前にまとめて置く（見分けに使わない日付も。設計書 §4.4）。登録済みと同じキーでも、日付の誤りはエラー
        $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => $error]], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('skippedRows'));
    }

    public function test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        // 以前の二重送信の名残（片づけはしない。照合は id のいちばん小さいものを理由に出す。設計書 §4.3）
        $first = $this->registered($this->unit1A, $this->customer, '2026-04-01');
        $this->registered($this->unit1A, $this->customer, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $first->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, Contract::count(), '重複の片づけはしない（消さない）');
    }

    // ================================================================
    // 5・7・8: 削除した契約／まだ無い顧客／タブをまたぐ
    // ================================================================

    public function test_a_contract_row_matching_only_a_deleted_contract_is_imported(): void
    {
        $this->registered($this->unit1A, $this->customer, '2026-04-01')->delete();

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '削除した契約と突き合わせてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_past_contract_row_matching_only_a_deleted_contract_is_imported(): void
    {
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'])->delete();

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '削除した契約と突き合わせてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_past_contract_row_for_a_new_customer_is_not_matched_to_a_contract_without_a_customer(): void
    {
        // まだ無い顧客（取込で自動作成する）の行を「顧客の無い契約」と取り違えない（設計書 §4.3）
        $this->registered($this->unit1A, null, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $csv = self::PAST_HEADER . "\n再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $this->assertSame([], $preview->viewData('skippedRows'), 'まだ無い顧客の行を「顧客の無い契約」と取り違えてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('past-contract', $csv)->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録、顧客 1件を自動作成');
        $this->assertSame(1, Customer::where('name', '新しい商事')->count());
    }

    public function test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab(): void
    {
        $this->confirm('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n");
        $registered = Contract::sole();
        $registered->update(['status' => 'terminated', 'contract_end_date' => '2026-08-31']);

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,2026-08-31,95000,,,,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    public function test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab(): void
    {
        $this->confirm('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n");
        $registered = Contract::sole();

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,90000,,,,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PAST_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    // ================================================================
    // 9: CSV の中の重複
    // ================================================================

    /** @return array<string, array{0: string}> [2 行目。1 行目は CONTRACT_ROW_1] */
    public static function contractDuplicateRows(): array
    {
        return [
            '同じ行'                         => [self::CONTRACT_ROW_1],
            '階を空欄にして部屋番号に書いた' => ['再取込ビル,,1A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '日付の書き方が違う'             => ['再取込ビル,1,A,再取込商事,2026/4/1,2026/4/1,95000,,,,,,'],
            '家賃が違う'                     => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,120000,,,,,,'],
        ];
    }

    #[DataProvider('contractDuplicateRows')]
    public function test_the_second_row_with_the_same_key_in_a_contract_csv_is_an_error(string $second): void
    {
        $csv = self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n{$second}\n";

        $preview = $this->preview('contract', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('行3: CSV内で行2と同じ契約が重複しています', $preview->getContent());

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');
        $this->assertSame(1, Contract::count());
    }

    /** @return array<string, array{0: string, 1: string}> [1 行目, 2 行目]。見分けのキーが 1 つだけ違う */
    public static function contractRowsThatDifferInOneKeyElement(): array
    {
        return [
            '区画'         => [self::CONTRACT_ROW_1, '再取込ビル,2,A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客'         => [self::CONTRACT_ROW_1, '再取込ビル,1,A,別商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客（空欄）' => [self::CONTRACT_ROW_1, '再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,'],
            '契約日'       => [self::CONTRACT_ROW_1, '再取込ビル,1,A,再取込商事,2026-04-02,2026-04-02,95000,,,,,,'],
        ];
    }

    #[DataProvider('contractRowsThatDifferInOneKeyElement')]
    public function test_two_contract_rows_that_differ_in_one_key_element_are_both_imported(string $first, string $second): void
    {
        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$first}\n{$second}\n")->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'), '見分けのキーが違う 2 行を CSV 内の重複にした');
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: string}>
     *   [URL のタブ, 見出し, 登録済みと同じ行, 契約日, 登録済みの契約の追加の列, スキップの理由の書式]
     */
    public static function registeredRowsWrittenTwice(): array
    {
        return [
            '契約'     => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, '2026-04-01', [], self::CONTRACT_SKIP],
            '過去契約' => ['past-contract', self::PAST_HEADER, self::PAST_ROW_1, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], self::PAST_SKIP],
        ];
    }

    #[DataProvider('registeredRowsWrittenTwice')]
    public function test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(
        string $tab, string $header, string $row, string $date, array $extra, string $skipFormat
    ): void {
        // 最初の行は、照合の前に覚える（設計書 §4.5）。1 行目はスキップ・2 行目は重複のエラー
        $registered = $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$row}\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [同じ行を 2 回書く] */
    public static function pastDuplicateRows(): array
    {
        return [
            '顧客は登録済み' => [self::PAST_ROW_1],
            '顧客はまだ無い' => ['再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
        ];
    }

    #[DataProvider('pastDuplicateRows')]
    public function test_the_second_row_with_the_same_key_in_a_past_contract_csv_is_an_error(string $row): void
    {
        $csv = self::PAST_HEADER . "\n{$row}\n{$row}\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();

        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_two_past_contract_rows_for_different_new_customers_are_both_imported(): void
    {
        // まだ無い顧客は名前で比べる（同じ区画・同じ契約日でも、名前が違えば別の契約）
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n"
            . "再取込ビル,1,A,新しい商店,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    // ================================================================
    // 10: 警告はスキップ・エラーの行には出さない
    // ================================================================

    public function test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registered($this->unit1A, $this->customer, '2026-04-01');   // 契約中
        $csv = self::CONTRACT_HEADER . "\n"
            . self::CONTRACT_ROW_1 . "\n"                                            // 行2: 登録済み → スキップ
            . "再取込ビル,1,A,別商事,2026-06-01,2026-06-01,90000,,,,,,\n"               // 行3: 取り込む（同じ区画に契約中がある）
            . "再取込ビル,1,A,別商事,2026-07-01,2026-07-01,abc,,,,,,\n";                 // 行4: 家賃の誤り → エラー

        $preview = $this->preview('contract', $csv)->assertOk();
        $html = $preview->getContent();
        $warning = "区画「再取込ビル 1A」には既にアクティブな契約（{$registered->contract_number}）が存在します";

        // 役割: 警告は取り込む行（行3）だけ
        $this->assertSame([['row' => 3, 'message' => $warning]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 4, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示: 警告の一覧に行3だけが出る
        $this->assertSame(1, substr_count($html, '⚠ 行3: ' . $warning));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行4:', $html);
    }

    public function test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $this->registered($this->unit1A, $this->other, '2021-04-01');   // 契約中（期間の重なりの相手）
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2099-03-31,90000,,,,,,\n"   // 行2: 登録済み → スキップ
            . "再取込ビル,1,A,再取込商事,2022-04-01,2022-04-01,2099-03-31,abc,,,,,,\n"     // 行3: 家賃の誤り → エラー
            . "再取込ビル,1,A,再取込商事,2024-04-01,2024-04-01,2099-03-31,90000,,,,,,\n";  // 行4: 取り込む

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $future = '解約日（2099-03-31）が今日より未来です（過去契約として登録します）';
        $overlap = '区画「再取込ビル 1A」に期間が重なるアクティブ契約があります（取込は実行）';

        // 役割: どの行も「解約日が未来」「期間の重なり」に当たるが、警告は取り込む行（行4）だけ
        $this->assertSame([['row' => 4, 'message' => $future], ['row' => 4, 'message' => $overlap]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $future));
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $overlap));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
    }

    public function test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row(): void
    {
        $unit3A = $this->unit(3, 'A');
        $registered = $this->registered($unit3A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $unit3A->delete();
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,3,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n"   // 行2: 登録済み → スキップ
            . "再取込ビル,3,A,再取込商事,2016-04-01,2016-04-01,2019-03-31,90000,,,,,,\n";  // 行3: 取り込む

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $notice = '物件「再取込ビル」の区画「3A」は削除済みです。削除済みの区画のまま過去契約として取り込みます';

        $this->assertSame([['row' => 3, 'message' => $notice]], $preview->viewData('warnings'));
        // 削除済みの区画でも、スキップの理由は表示名で書く（今の警告と同じ）
        $this->assertSame([['row' => 2, 'message' => "区画「再取込ビル 3A」の契約（契約日 2020-04-01・顧客 再取込商事）は既に登録済み（{$registered->contract_number}）のためスキップ"]], $preview->viewData('skippedRows'));
        $this->assertStringNotContainsString('⚠ 行2:', $preview->getContent());
    }

    // ================================================================
    // 11: プレビューのあとに同じ契約が登録された
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array<string, string>, 6: string}> */
    public static function registeredAfterPreviewCases(): array
    {
        return [
            '契約' => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, self::CONTRACT_ROW_2, '2026-04-01', [], '契約インポート完了: 1件を登録しました'],
            '過去契約' => [
                'past-contract', self::PAST_HEADER, self::PAST_ROW_1, self::PAST_ROW_2, '2020-04-01',
                ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], '過去契約インポート完了: 契約 1件を登録',
            ],
        ];
    }

    #[DataProvider('registeredAfterPreviewCases')]
    public function test_a_contract_registered_after_the_preview_is_skipped_at_confirmation(
        string $tab, string $header, string $first, string $second, string $date, array $extra, string $success
    ): void {
        $form = $this->parseImportForm($this->preview($tab, "{$header}\n{$first}\n{$second}\n")->getContent(), $tab);

        // プレビューのあとで、1 行目と同じ契約が（別の画面・別のタブで）登録された
        $this->registered($this->unit1A, $this->customer, $date, $extra);

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])
            ->assertRedirect(route('admin.tenant-import', ['tab' => $tab]))
            ->assertSessionHas('success', $success);
        $this->assertSame(2, Contract::count(), '確定で、プレビューのあとに登録された契約をもう一度入れた');
    }

    // ================================================================
    // 13: 登録済みの行は、金額に誤りがあってもスキップ
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array<string, string>, 6: string}> */
    public static function badAmountCases(): array
    {
        return [
            '契約' => [
                'contract', self::CONTRACT_HEADER,
                '再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,abc,,,,,,',
                '再取込ビル,2,A,別商事,2026-05-01,2026-05-01,abc,,,,,,',
                '2026-04-01', [], self::CONTRACT_SKIP,
            ],
            '過去契約' => [
                'past-contract', self::PAST_HEADER,
                '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,abc,,,,,,',
                '再取込ビル,2,A,別商事,2019-04-01,2019-04-01,2021-03-31,abc,,,,,,',
                '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], self::PAST_SKIP,
            ],
        ];
    }

    #[DataProvider('badAmountCases')]
    public function test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error(
        string $tab, string $header, string $registeredRow, string $unregisteredRow, string $date, array $extra, string $skipFormat
    ): void {
        $registered = $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$registeredRow}\n{$unregisteredRow}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 12: 共通部品の灰色の文／赤字の文（物件タブ）
    // ================================================================

    public function test_a_property_csv_whose_rows_are_all_registered_shows_the_gray_notice(): void
    {
        // 再取込ビル は setUp で登録済み
        $preview = $this->assertPreviewSkipsEveryRow('property', self::PROPERTY_HEADER . "\n再取込ビル,790-0001,愛媛県松山市,,,,,,\n", 1);

        $this->assertSame([['row' => 2, 'message' => '物件「再取込ビル」は既に登録済みのためスキップ']], $preview->viewData('skippedRows'));
    }

    public function test_a_property_csv_with_an_error_row_keeps_the_red_notice(): void
    {
        $preview = $this->assertPreviewOffersNoImport('property', self::PROPERTY_HEADER . "\n再取込ビル,790-0001,愛媛県松山市,,,,,,\n新しいビル,,,,,,,,\n");

        $this->assertCount(1, $preview->viewData('skippedRows'), 'スキップの行が無い（灰色と赤字の分かれ目を測れていない）');
        $this->assertSame([['row' => 3, 'message' => '住所が未入力です']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('すべての行が登録済みです', $preview->getContent());
    }

    // ================================================================
    // 14: タブの説明
    // ================================================================

    public function test_the_contract_tabs_explain_that_registered_contracts_are_skipped(): void
    {
        $html = $this->actingAs($this->executive())->get(route('admin.tenant-import'))->assertOk()->getContent();

        // タブごとに見る（どちらかのタブに 2 行とも入っていても、数だけでは分からない）
        $contractTab = $this->between($html, "x-show=\"activeTab === 'contract'\"", "x-show=\"activeTab === 'past-contract'\"");
        $pastTab = $this->between($html, "x-show=\"activeTab === 'past-contract'\"", '<script>');
        $this->assertSame(1, substr_count($contractTab, self::TAB_NOTE), '契約タブの説明に出ていない');
        $this->assertSame(1, substr_count($pastTab, self::TAB_NOTE), '過去契約タブの説明に出ていない');
    }

    // ================================================================
    // 部品
    // ================================================================

    private function unit(int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $this->property->id, 'floor' => $floor, 'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
    }

    /**
     * 画面で登録した契約の代わり（モデルで直接作る）。契約番号は取込が付ける番号（C-{今年}-NNN・C-{契約日の年}-NNN）と
     * ぶつからない年にする
     *
     * @param  array<string, mixed>  $overrides
     */
    private function registered(Unit $unit, ?Customer $customer, string $contractDate, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'contract_number' => 'C-1999-' . (900 + ++$this->seq),
            'department' => 'tenant', 'property_id' => $this->property->id, 'unit_id' => $unit->id,
            'customer_id' => $customer?->id, 'status' => 'active',
            'contract_date' => $contractDate, 'rent_start_date' => $contractDate, 'rent' => 95000,
        ], $overrides));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "画面に {$from} が無い");
        $end = strpos($html, $to, $start + strlen($from));
        $this->assertNotFalse($end, "画面の {$from} のあとに {$to} が無い");

        return substr($html, $start, $end - $start);
    }
}
