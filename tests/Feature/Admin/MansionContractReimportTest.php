<?php

namespace Tests\Feature\Admin;

use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの部屋契約・駐車場契約の CSV 取込を、上げ直しても二重にしない（設計書 2026-09-29-contract-reimport-design.md）。
 *
 * ⚠ 直す前（2026-09-29 に試作で実測）: 同じ CSV を上げ直すと、契約中の行は警告のうえ・退去済みの行は何も出ずに、同じ契約がもう一度入った。
 * ⚠ 見分けのキーは 部屋（駐車場）・入居者・契約日・入居日（開始日）（設計書 §4.2）。日付は任意の列なので、
 *   空欄どうしは同じ・片方だけ空欄なら違う契約とみなす。賃料・状態・退去日（終了日）・メモは使わない。
 * ⚠ どれも、プレビューが描いた確定のフォームを分解してそのまま送り返す往復（SubmitsImportPreview。Bug #47 / #54 ②）。
 *   スキップの理由は「行N: …」の全文で見て、役割（viewData）と表示（画面の文字）を別々に見る（Bug #43 / #54 ④）。
 * ⚠ 「入る」を見るテスト（見分けのキーが 1 つ違う・片方だけ空欄の日付）は、直す前のコードでも緑になる。
 *   照合が広すぎる書き換えを捕まえる役（計画 docs/superpowers/plans/2026-09-29-contract-reimport.md の変異テストで赤を確かめる）。
 * ⚠ URL のタブは room-contract / parking-contract（ハイフン）、戻り先のタブは room_contract / parking_contract（下線）。
 */
class MansionContractReimportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const ROOM_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';
    private const PARKING_HEADER = '物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ';
    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    private const ROOM_ROW_1 = '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,';
    private const ROOM_ROW_2 = '再取込ハイツ,102,鈴木次郎,2024-05-01,2024-05-15,,60000,,,,,';
    private const PARKING_ROW_1 = '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,8000,,';
    private const PARKING_ROW_2 = '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,9000,,,';

    /** ROOM_ROW_1 と同じ契約のスキップの理由（%d は登録済みの契約の ID） */
    private const ROOM_SKIP = '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 2024-04-01・入居日 2024-04-15）は既に登録済み（既存契約 ID: %d）のためスキップ';

    /** PARKING_ROW_1 と同じ契約のスキップの理由（%d は登録済みの契約の ID） */
    private const PARKING_SKIP = '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 2024-04-01・開始日 2024-04-15）は既に登録済み（既存契約 ID: %d）のためスキップ';

    private const ROOM_TAB_NOTE = '<li>同じ部屋・入居者名・契約日・入居日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>';
    private const PARKING_TAB_NOTE = '<li>同じ駐車場・入居者名・契約日・開始日の契約が登録済みなら、その行はスキップされます（月額料金などが違っても書き換えません）</li>';

    private MsRoom $room101;

    private MsParking $parkingP1;

    private MsTenant $yamada;

    private MsTenant $suzuki;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();

        $property = MsProperty::create([
            'property_code' => 'MS-RE-1', 'property_name' => '再取込ハイツ', 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市', 'created_by' => 1,
        ]);
        $this->room101 = MsRoom::create(['property_id' => $property->id, 'room_number' => '101', 'status' => 'vacant']);
        MsRoom::create(['property_id' => $property->id, 'room_number' => '102', 'status' => 'vacant']);
        $this->parkingP1 = MsParking::create(['property_id' => $property->id, 'parking_number' => 'P-1', 'monthly_fee' => 8000, 'status' => 'vacant']);
        MsParking::create(['property_id' => $property->id, 'parking_number' => 'P-2', 'monthly_fee' => 9000, 'status' => 'vacant']);
        $this->yamada = MsTenant::create(['tenant_type' => 'resident', 'name' => '山田太郎']);
        $this->suzuki = MsTenant::create(['tenant_type' => 'resident', 'name' => '鈴木次郎']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    // ================================================================
    // 1・2: 同じ CSV を上げ直す／行を足した CSV を上げ直す
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 数える表, 2 行の CSV] */
    public static function twoRowCsvs(): array
    {
        return [
            '部屋契約'   => ['room-contract', 'ms_contracts', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_2 . "\n"],
            '駐車場契約' => ['parking-contract', 'ms_parking_contracts', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_2 . "\n"],
        ];
    }

    #[DataProvider('twoRowCsvs')]
    public function test_uploading_the_same_csv_again_skips_every_row(string $tab, string $table, string $csv): void
    {
        $this->confirm($tab, $csv);
        $this->assertSame(2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        $this->assertPreviewSkipsEveryRow($tab, $csv, 2);
        $this->assertSame(2, DB::table($table)->count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}> */
    public static function addedRowCases(): array
    {
        return [
            '部屋契約' => ['room-contract', MsContract::class, self::ROOM_HEADER, self::ROOM_ROW_1, self::ROOM_ROW_2, self::ROOM_SKIP, '部屋契約インポート完了: 1件を登録しました'],
            '駐車場契約' => ['parking-contract', MsParkingContract::class, self::PARKING_HEADER, self::PARKING_ROW_1, self::PARKING_ROW_2, self::PARKING_SKIP, '駐車場契約インポート完了: 1件を登録しました'],
        ];
    }

    #[DataProvider('addedRowCases')]
    public function test_uploading_a_csv_with_an_added_row_imports_only_the_new_row(
        string $tab, string $model, string $header, string $first, string $added, string $skipFormat, string $success
    ): void {
        $this->confirm($tab, "{$header}\n{$first}\n");
        $registered = $model::sole();

        $csv = "{$header}\n{$first}\n{$added}\n";
        $preview = $this->preview($tab, $csv)->assertOk();
        $html = $preview->getContent();
        $message = sprintf($skipFormat, $registered->id);

        // 役割: 1 行目はスキップ（エラーではない）・足した行は取り込む
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        // 表示: 灰色の一覧に全文・件数のチップ・ボタンの下の注記（Bug #43: 全文で見る）
        $this->assertStringContainsString('行2: ' . $message, $html);
        $this->assertStringContainsString('スキップ: <strong>1</strong> 件', $html);
        $this->assertStringContainsString('※ 既存データ（1件）はスキップされます', $html);

        $this->confirm($tab, $csv)->assertSessionHas('success', $success);
        $this->assertSame(2, $model::count());
    }

    // ================================================================
    // 3・4: 見分けのキーが 1 つ違えば入る／キー以外が違ってもスキップ
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [URL のタブ, CSV の行]。登録済みは 101（P-1）・山田太郎・2024-04-01・2024-04-15 */
    public static function rowsWithAnotherKey(): array
    {
        return [
            '部屋契約: 部屋が違う'       => ['room-contract', '再取込ハイツ,102,山田太郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 入居者が違う'     => ['room-contract', '再取込ハイツ,101,鈴木次郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 契約日が違う'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-02,2024-04-15,,,,,,,'],
            '部屋契約: 入居日が違う'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-16,,,,,,,'],
            '駐車場契約: 駐車場が違う'   => ['parking-contract', '再取込ハイツ,P-2,山田太郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 入居者が違う'   => ['parking-contract', '再取込ハイツ,P-1,鈴木次郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 契約日が違う'   => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-02,2024-04-15,,8000,,,'],
            '駐車場契約: 開始日が違う'   => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-16,,8000,,,'],
        ];
    }

    #[DataProvider('rowsWithAnotherKey')]
    public function test_a_row_that_differs_in_one_key_element_is_imported(string $tab, string $row): void
    {
        $this->registeredRoomContract('2024-04-01', '2024-04-15');
        $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string, 1: string}> [URL のタブ, CSV の行]。登録済みは ROOM_ROW_1 / PARKING_ROW_1 を取り込んだもの */
    public static function rowsWithTheSameKey(): array
    {
        return [
            '部屋契約: 家賃を改定した'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,60000,3000,55000,55000,,'],
            '部屋契約: 退去した'           => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,2025-03-31,55000,3000,55000,55000,,'],
            '部屋契約: メモが違う'         => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,メモを足した'],
            '部屋契約: 担当者が違う'       => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,いない人,'],
            '部屋契約: 日付の書き方が違う' => ['room-contract', '再取込ハイツ,101,山田太郎,2024/4/1,2024/4/15,,55000,3000,55000,55000,,'],
            '駐車場契約: 月額料金を改定した' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,9000,8000,,'],
            '駐車場契約: 終了した'         => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,2025-03-31,8000,8000,,'],
            '駐車場契約: 敷金が違う'       => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,16000,,'],
            '駐車場契約: 紐付部屋番号が違う' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,102,2024-04-01,2024-04-15,,8000,8000,,'],
            '駐車場契約: 日付の書き方が違う' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024/4/1,2024/4/15,,8000,8000,,'],
        ];
    }

    #[DataProvider('rowsWithTheSameKey')]
    public function test_a_row_that_differs_only_outside_the_key_is_skipped(string $tab, string $row): void
    {
        $room = $tab === 'room-contract';
        $this->confirm($tab, $this->header($tab) . "\n" . ($room ? self::ROOM_ROW_1 : self::PARKING_ROW_1) . "\n");
        $registered = $room ? MsContract::sole() : MsParkingContract::sole();

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($room ? self::ROOM_SKIP : self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
        // スキップの行には警告を出さない（担当者が見つからない・紐付部屋に契約が無い、はこの行の警告だった）
        $this->assertSame([], $preview->viewData('warnings'));
    }

    public function test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        $first = $this->registeredRoomContract('2024-04-01', '2024-04-15');
        $this->registeredRoomContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('room-contract', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::ROOM_SKIP, $first->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, MsContract::count(), '重複の片づけはしない（消さない）');
    }

    public function test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        $first = $this->registeredParkingContract('2024-04-01', '2024-04-15');
        $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PARKING_SKIP, $first->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, MsParkingContract::count(), '重複の片づけはしない（消さない）');
    }

    // ================================================================
    // 6: 日付が空欄
    // ================================================================

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string, 4: string, 5: bool}>
     *   [URL のタブ, 登録済みの契約日, 登録済みの入居日（開始日）, CSV の契約日, CSV の入居日（開始日）, スキップするか]
     */
    public static function blankDateCases(): array
    {
        $cases = [];
        foreach (['部屋契約' => 'room-contract', '駐車場契約' => 'parking-contract'] as $label => $tab) {
            $second = $tab === 'room-contract' ? '入居日' : '開始日';
            $cases += [
                "{$label}: 2 つとも空欄どうし"               => [$tab, null, null, '', '', true],
                "{$label}: 契約日は空欄どうし・{$second}は同じ" => [$tab, null, '2024-04-15', '', '2024-04-15', true],
                "{$label}: 契約日だけ登録済みが空欄"         => [$tab, null, '2024-04-15', '2024-04-01', '2024-04-15', false],
                "{$label}: 契約日だけ CSV が空欄"            => [$tab, '2024-04-01', '2024-04-15', '', '2024-04-15', false],
                "{$label}: {$second}だけ登録済みが空欄"      => [$tab, '2024-04-01', null, '2024-04-01', '2024-04-15', false],
                "{$label}: {$second}だけ CSV が空欄"         => [$tab, '2024-04-01', '2024-04-15', '2024-04-01', '', false],
            ];
        }

        return $cases;
    }

    #[DataProvider('blankDateCases')]
    public function test_blank_dates_match_only_blank_dates(string $tab, ?string $registeredContract, ?string $registeredSecond, string $csvContract, string $csvSecond, bool $skip): void
    {
        $room = $tab === 'room-contract';
        $registered = $room
            ? $this->registeredRoomContract($registeredContract, $registeredSecond)
            : $this->registeredParkingContract($registeredContract, $registeredSecond);
        $row = $room
            ? "再取込ハイツ,101,山田太郎,{$csvContract},{$csvSecond},,55000,,,,,"
            : "再取込ハイツ,P-1,山田太郎,,{$csvContract},{$csvSecond},,8000,,,";

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        if (! $skip) {
            $this->assertSame([], $preview->viewData('skippedRows'), '片方だけ空欄の日付を同じとみなしてスキップした');
            $this->assertSame(1, $preview->viewData('validCount'));

            return;
        }
        $message = sprintf(
            $room
                ? '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 %s・入居日 %s）は既に登録済み（既存契約 ID: %d）のためスキップ'
                : '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 %s・開始日 %s）は既に登録済み（既存契約 ID: %d）のためスキップ',
            $csvContract === '' ? '空欄' : $csvContract,
            $csvSecond === '' ? '空欄' : $csvSecond,
            $registered->id
        );
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertStringContainsString('行2: ' . $message, $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 日付が空欄の行, スキップの理由（%d は登録済みの契約の ID）] */
    public static function blankDateRoundTripCases(): array
    {
        return [
            '部屋契約' => [
                'room-contract', '再取込ハイツ,101,山田太郎,,,,55000,,,,,',
                '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 空欄・入居日 空欄）は既に登録済み（既存契約 ID: %d）のためスキップ',
            ],
            '駐車場契約' => [
                'parking-contract', '再取込ハイツ,P-1,山田太郎,,,,,8000,,,',
                '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 空欄・開始日 空欄）は既に登録済み（既存契約 ID: %d）のためスキップ',
            ],
        ];
    }

    #[DataProvider('blankDateRoundTripCases')]
    public function test_a_contract_without_dates_is_skipped_when_uploaded_again(string $tab, string $row, string $skipFormat): void
    {
        // 移行の CSV は日付が無いことが多い。日付が空欄の契約を取り込み、同じ CSV を上げ直す（空欄どうしを同じとみなす）
        $csv = $this->header($tab) . "\n{$row}\n";
        $this->confirm($tab, $csv);
        $registered = $tab === 'room-contract' ? MsContract::sole() : MsParkingContract::sole();

        $preview = $this->assertPreviewSkipsEveryRow($tab, $csv, 1);

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->id)]], $preview->viewData('skippedRows'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 登録済みと同じキーの行, エラー] */
    public static function badDateOnRegisteredRowCases(): array
    {
        return [
            '部屋契約: 退去日'   => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,2025-02-30,55000,,,,,', '退去日「2025-02-30」の形式が不正です'],
            '駐車場契約: 終了日' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,2025-02-30,8000,,,', '終了日「2025-02-30」の形式が不正です'],
        ];
    }

    #[DataProvider('badDateOnRegisteredRowCases')]
    public function test_a_registered_row_with_a_bad_date_is_an_error_not_skipped(string $tab, string $row, string $error): void
    {
        // 日付の検査は照合の前にまとめて置く（見分けに使わない退去日・終了日も。設計書 §4.4）。登録済みと同じキーでも、日付の誤りはエラー
        $tab === 'room-contract'
            ? $this->registeredRoomContract('2024-04-01', '2024-04-15')
            : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => $error]], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('skippedRows'));
    }

    // ================================================================
    // 9: CSV の中の重複
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 1 行目, 2 行目]（見分けのキーが同じ） */
    public static function duplicateRows(): array
    {
        return [
            '部屋契約: 同じ行'               => ['room-contract', self::ROOM_ROW_1, self::ROOM_ROW_1],
            '部屋契約: 日付の書き方が違う'   => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024/4/1,2024/4/15,,55000,,,,,'],
            '部屋契約: 日付が 2 つとも空欄'  => ['room-contract', '再取込ハイツ,101,山田太郎,,,,55000,,,,,', '再取込ハイツ,101,山田太郎,,,,60000,,,,,'],
            '駐車場契約: 同じ行'             => ['parking-contract', self::PARKING_ROW_1, self::PARKING_ROW_1],
            '駐車場契約: 日付の書き方が違う' => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024/4/1,2024/4/15,,8000,,,'],
        ];
    }

    #[DataProvider('duplicateRows')]
    public function test_the_second_row_with_the_same_key_in_the_csv_is_an_error(string $tab, string $first, string $second): void
    {
        $csv = $this->header($tab) . "\n{$first}\n{$second}\n";

        $preview = $this->preview($tab, $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('行3: CSV内で行2と同じ契約が重複しています', $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 1 行目, 2 行目]（見分けのキーが 1 つだけ違う） */
    public static function rowsThatDifferInOneKeyElement(): array
    {
        return [
            '部屋契約: 部屋'           => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,102,山田太郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 入居者'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,鈴木次郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 契約日'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024-04-02,2024-04-15,,,,,,,'],
            '部屋契約: 入居日'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-16,,,,,,,'],
            '部屋契約: 契約日が空欄'   => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,,2024-04-15,,,,,,,'],
            '駐車場契約: 駐車場'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-2,山田太郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 入居者'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,鈴木次郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 契約日'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-02,2024-04-15,,8000,,,'],
            '駐車場契約: 開始日'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-16,,8000,,,'],
            '駐車場契約: 開始日が空欄' => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-01,,,8000,,,'],
        ];
    }

    #[DataProvider('rowsThatDifferInOneKeyElement')]
    public function test_two_rows_that_differ_in_one_key_element_are_both_imported(string $tab, string $first, string $second): void
    {
        $preview = $this->preview($tab, $this->header($tab) . "\n{$first}\n{$second}\n")->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'), '見分けのキーが違う 2 行を CSV 内の重複にした');
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    public function test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(): void
    {
        // 最初の行は、照合の前に覚える（設計書 §4.5）。1 行目はスキップ・2 行目は重複のエラー
        $registered = $this->registeredRoomContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('room-contract', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::ROOM_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
    }

    public function test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(): void
    {
        $registered = $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 10: 警告はスキップ・エラーの行には出さない
    // ================================================================

    public function test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registeredRoomContract('2024-04-01', '2024-04-15');   // 101 は契約中
        $csv = self::ROOM_HEADER . "\n"
            . "再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,,,,いない人,\n"   // 行2: 登録済み → スキップ
            . "再取込ハイツ,101,鈴木次郎,2025-04-01,,,abc,,,,いない人,\n"              // 行3: 家賃の誤り → エラー
            . "再取込ハイツ,101,鈴木次郎,2025-05-01,,,60000,,,,いない人,\n";           // 行4: 取り込む

        $preview = $this->preview('room-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $staff = '担当者ユーザー名「いない人」がシステムに見つからないため、担当者は未設定でインポートします';
        $active = "部屋「再取込ハイツ 101」には既に契約中の入居者がいます（既存契約 ID: {$registered->id}）";

        // 役割: 警告は取り込む行（行4）だけ
        $this->assertSame([['row' => 4, 'message' => $staff], ['row' => 4, 'message' => $active]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示: 警告の一覧・件数のチップ・ボタンの下の注記（どれも取り込む行の警告だけを数える）
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $staff));
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $active));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
        $this->assertStringContainsString('警告: <strong>2</strong> 件', $html);
        $this->assertStringContainsString('※ 警告のある行（2件）もそのまま登録されます', $html);
    }

    public function test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registeredParkingContract('2024-04-01', '2024-04-15');   // P-1 は使用中
        $csv = self::PARKING_HEADER . "\n"
            . "再取込ハイツ,P-1,山田太郎,102,2024-04-01,2024-04-15,,8000,,いない人,\n"   // 行2: 登録済み → スキップ（102 に契約が無い）
            . "再取込ハイツ,P-1,鈴木次郎,999,2025-04-01,,,abc,,いない人,\n"             // 行3: 月額料金の誤り → エラー（999 が無い）
            . "再取込ハイツ,P-1,鈴木次郎,999,2025-05-01,,,9000,,いない人,\n";           // 行4: 取り込む

        $preview = $this->preview('parking-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $linked = '紐付部屋番号「999」が物件「再取込ハイツ」に見つからないため、部屋契約との紐付けはスキップします';
        $staff = '担当者ユーザー名「いない人」がシステムに見つからないため、担当者は未設定でインポートします';
        $active = "駐車場「再取込ハイツ P-1」には既に使用中の契約があります（既存契約 ID: {$registered->id}）";

        $this->assertSame(
            [['row' => 4, 'message' => $linked], ['row' => 4, 'message' => $staff], ['row' => 4, 'message' => $active]],
            $preview->viewData('warnings')
        );
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '月額料金「abc」は0以上の整数で入力してください']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
        $this->assertStringContainsString('警告: <strong>3</strong> 件', $html);
        $this->assertStringNotContainsString('有効な部屋契約が見つからないため', $html);
    }

    // ================================================================
    // 11: プレビューのあとに同じ契約が登録された
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function registeredAfterPreviewCases(): array
    {
        return [
            '部屋契約'   => ['room-contract', MsContract::class, '部屋契約インポート完了: 1件を登録しました'],
            '駐車場契約' => ['parking-contract', MsParkingContract::class, '駐車場契約インポート完了: 1件を登録しました'],
        ];
    }

    #[DataProvider('registeredAfterPreviewCases')]
    public function test_a_contract_registered_after_the_preview_is_skipped_at_confirmation(string $tab, string $model, string $success): void
    {
        $room = $tab === 'room-contract';
        $csv = $this->header($tab) . "\n"
            . ($room ? self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_2 : self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_2) . "\n";
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        // プレビューのあとで、1 行目と同じ契約が（別の画面で）登録された
        $room ? $this->registeredRoomContract('2024-04-01', '2024-04-15') : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])
            ->assertSessionHas('success', $success);
        $this->assertSame(2, $model::count(), '確定で、プレビューのあとに登録された契約をもう一度入れた');
    }

    // ================================================================
    // 13: 登録済みの行は、金額に誤りがあってもスキップ（駐車場契約は日付の検査を金額の前へ移した）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> [URL のタブ, 登録済みの行, 登録済みでない行, 登録済みでない行のエラー] */
    public static function badAmountCases(): array
    {
        return [
            '部屋契約: 家賃' => [
                'room-contract',
                '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,abc,,,,,',
                '再取込ハイツ,102,鈴木次郎,2024-05-01,2024-05-15,,abc,,,,,',
                '家賃「abc」は不正な値です',
            ],
            '駐車場契約: 月額料金' => [
                'parking-contract',
                '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,abc,,,',
                '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,abc,,,',
                '月額料金「abc」は0以上の整数で入力してください',
            ],
            '駐車場契約: 敷金' => [
                'parking-contract',
                '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,abc,,',
                '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,9000,abc,,',
                '敷金「abc」は不正な値です',
            ],
        ];
    }

    #[DataProvider('badAmountCases')]
    public function test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error(string $tab, string $registeredRow, string $unregisteredRow, string $error): void
    {
        $room = $tab === 'room-contract';
        $registered = $room ? $this->registeredRoomContract('2024-04-01', '2024-04-15') : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$registeredRow}\n{$unregisteredRow}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($room ? self::ROOM_SKIP : self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => $error]], $preview->viewData('rowErrors'));
    }

    public function test_a_parking_row_with_both_a_bad_date_and_a_bad_fee_reports_the_date(): void
    {
        // 日付の検査を金額の前へ移したので、両方に誤りがある行は日付の誤りが出る（設計書 §4.4。どちらでも行はエラー）
        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-02-30,,abc,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => '開始日「2024-02-30」の形式が不正です']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 12: 共通部品の灰色の文／赤字の文（入居者タブ）
    // ================================================================

    public function test_a_tenant_csv_whose_rows_are_all_registered_shows_the_gray_notice(): void
    {
        // 山田太郎 は setUp で登録済み
        $preview = $this->assertPreviewSkipsEveryRow('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n", 1);

        $this->assertSame([['row' => 2, 'message' => '入居者「山田太郎」は既に登録済みのためスキップ']], $preview->viewData('skippedRows'));
    }

    public function test_a_tenant_csv_with_an_error_row_keeps_the_red_notice(): void
    {
        $preview = $this->assertPreviewOffersNoImport('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n入居者,,,,,,,,\n");

        $this->assertCount(1, $preview->viewData('skippedRows'), 'スキップの行が無い（灰色と赤字の分かれ目を測れていない）');
        $this->assertSame([['row' => 3, 'message' => '氏名が未入力です']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('すべての行が登録済みです', $preview->getContent());
    }

    // ================================================================
    // 14: タブの説明
    // ================================================================

    public function test_the_contract_tabs_explain_that_registered_contracts_are_skipped(): void
    {
        $html = $this->actingAs($this->executive())->get(route('admin.mansion-import'))->assertOk()->getContent();

        $roomTab = $this->between($html, "x-show=\"activeTab === 'room_contract'\"", "x-show=\"activeTab === 'parking_contract'\"");
        $parkingTab = $this->between($html, "x-show=\"activeTab === 'parking_contract'\"", '<script>');
        $this->assertSame(1, substr_count($roomTab, self::ROOM_TAB_NOTE), '部屋契約タブの説明に出ていない');
        $this->assertSame(1, substr_count($parkingTab, self::PARKING_TAB_NOTE), '駐車場契約タブの説明に出ていない');
    }

    // ================================================================
    // 部品
    // ================================================================

    private function header(string $tab): string
    {
        return $tab === 'room-contract' ? self::ROOM_HEADER : self::PARKING_HEADER;
    }

    /** 画面で登録した部屋契約の代わり（モデルで直接作る。101・山田太郎） */
    private function registeredRoomContract(?string $contractDate, ?string $moveInDate): MsContract
    {
        return MsContract::create([
            'room_id' => $this->room101->id, 'tenant_id' => $this->yamada->id, 'status' => 'active',
            'contract_date' => $contractDate, 'move_in_date' => $moveInDate, 'rent' => 55000, 'created_by' => 1,
        ]);
    }

    /** 画面で登録した駐車場契約の代わり（モデルで直接作る。P-1・山田太郎） */
    private function registeredParkingContract(?string $contractDate, ?string $startDate): MsParkingContract
    {
        return MsParkingContract::create([
            'parking_id' => $this->parkingP1->id, 'tenant_id' => $this->yamada->id, 'status' => 'active',
            'contract_date' => $contractDate, 'start_date' => $startDate, 'monthly_fee' => 8000, 'created_by' => 1,
        ]);
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
