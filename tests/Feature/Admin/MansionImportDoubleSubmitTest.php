<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\MansionImportController;
use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込（6 タブ）の確定を、確認画面 1 つにつき 1 回だけ通す（設計書 2026-09-28-import-double-submit-design.md）。
 *
 * ⚠ 直す前（2026-09-28 に実測）: 同じ確認画面の確定を 2 回送ると、部屋契約・駐車場契約は 2 件が 4 件になった。
 *   物件・部屋・駐車場・入居者は重複の確認で 2 回目が「0件を登録しました」になり、取り込み直したように見えた。
 * ⚠ タブは MansionImportController の public な execute{X} を機械的に列挙し、下の TABS と突き合わせる
 *   （TenantImportDoubleSubmitTest と同じ理由）。⚠ URL の区切りは room-contract（ハイフン）、
 *   戻り先のタブのキーは room_contract（下線）で、そろっていない。表は両方を持つ。
 */
class MansionImportDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;
    use ChecksDoubleSubmit;

    private const PROPERTY_NAME = '二重送信レジデンス';

    /** execute{X} => [URL のタブ, 戻り先のタブ, 数える表, 2 回目の断りの全文（設計書 §4.4）] */
    private const TABS = [
        'executeProperty' => [
            'property', 'property', 'ms_properties',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeRoom' => [
            'room', 'room', 'ms_rooms',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」から開く物件の詳細で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeParking' => [
            'parking', 'parking', 'ms_parkings',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」から開く物件の詳細で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeTenant' => [
            'tenant', 'tenant', 'ms_tenants',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「入居者管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeRoomContract' => [
            'room-contract', 'room_contract', 'ms_contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「部屋契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeParkingContract' => [
            'parking-contract', 'parking_contract', 'ms_parking_contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「駐車場契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->user = $this->executive();
    }

    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    /** @return array<string, array{0: string}> */
    public static function tabs(): array
    {
        $methods = array_keys(self::TABS);

        return array_combine($methods, array_map(fn (string $method) => [$method], $methods));
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

    public function test_every_tab_is_classified(): void
    {
        $methods = [];
        foreach ((new ReflectionClass(MansionImportController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === MansionImportController::class && preg_match('/^execute[A-Z]/', $method->name) === 1) {
                $methods[] = $method->name;
            }
        }
        sort($methods);

        $classified = array_keys(self::TABS);
        sort($classified);

        $this->assertSame($classified, $methods, 'TABS と MansionImportController の execute{X} がそろっていない（新しいタブは、コントローラの断りの表とこのテストの両方に足す）');
    }

    #[DataProvider('tabs')]
    public function test_sending_the_same_confirmation_twice_imports_once(string $method): void
    {
        [$tab, $returnTab, $table, $message] = self::TABS[$method];
        $csv  = $this->arrange($method);
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        $before = DB::table($table)->count();
        $this->send($tab, $form);
        $this->assertSame($before + 2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->send($tab, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertSame($before + 2, DB::table($table)->count(), '2 回目の送信で、もう一度入った');
        $this->assertRefused($second, route('admin.mansion-import', ['selected_tab' => $returnTab]), $message, $this->user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $form = $this->parseImportForm($this->preview('property', $this->arrange('executeProperty'))->getContent(), 'property');

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->send('property', $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, MsProperty::count());
        $this->assertRefused($response, route('admin.mansion-import', ['selected_tab' => 'property']), self::TABS['executeProperty'][3], $this->user);
    }

    public function test_uploading_the_same_file_again_issues_a_new_token(): void
    {
        $csv    = $this->arrange('executeProperty');
        $first  = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');
        $second = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');

        // ⚠ 同じファイルで確かめる（TenantImportDoubleSubmitTest と同じ理由）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->send('property', $second);
        $this->assertSame(2, MsProperty::count(), '上げ直したプレビューの鍵で取り込めない');
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview('property', $this->arrange('executeProperty'))->getContent();

        $this->assertSubmitOnceForm($html, url('/admin/mansion-import/property'));
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function send(string $tab, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->from(url($this->importBasePath() . "/{$tab}"))
            ->post($form['action'], $form['fields']);
    }

    /** タブごとの前提のデータを入れ、2 行の CSV を返す（2026-09-28 の実測と同じ中身） */
    private function arrange(string $method): string
    {
        switch ($method) {
            case 'executeProperty':
                return "物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考\n"
                    . "二重送信A棟,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,\n"
                    . "二重送信B棟,管理受託,オーナー甲,790-0002,愛媛県松山市一番町1-2,,,,,\n";

            case 'executeRoom':
                $this->property(99);

                return "物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考\n"
                    . self::PROPERTY_NAME . ",101,1,1K,25.50,空室,55000,3000,55000,55000,\n"
                    . self::PROPERTY_NAME . ",102,1,1K,25.50,入居中,56000,3000,,,\n";

            case 'executeParking':
                $this->property();

                return "物件名,駐車場番号,月額料金,状態,屋根あり,備考\n"
                    . self::PROPERTY_NAME . ",P-1,8000,空き,有,\n"
                    . self::PROPERTY_NAME . ",P-2,9000,使用中,無,\n";

            case 'executeTenant':
                return "区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考\n"
                    . "入居者,二重太郎,090-0000-0001,taro@example.com,,,,,\n"
                    . "駐車場利用のみ,二重花子,,,,,,,\n";

            case 'executeRoomContract':
                $property = $this->property();
                $this->room($property, '101', 'vacant');
                $this->room($property, '102', 'vacant');
                $this->tenant('二重太郎', 'resident');
                $this->tenant('二重次郎', 'resident');

                // 101 は退去日なし＝契約中、102 は退去日あり＝解約済み
                return "物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ\n"
                    . self::PROPERTY_NAME . ",101,二重太郎,2026-04-01,2026-04-15,,55000,3000,55000,55000,,\n"
                    . self::PROPERTY_NAME . ",102,二重次郎,2024-04-01,2024-04-15,2025-03-31,56000,3000,,,,\n";

            case 'executeParkingContract':
                $property = $this->property();
                $room     = $this->room($property, '101', 'occupied');
                $taro     = $this->tenant('二重太郎', 'resident');
                $this->tenant('二重花子', 'parking_only');
                // 紐付部屋番号 101 の契約中の部屋契約（紐付けの経路も通すため）
                MsContract::create(['room_id' => $room->id, 'tenant_id' => $taro->id, 'status' => 'active', 'created_by' => $this->user->id]);
                $this->parking($property, 'P-1', 8000);
                $this->parking($property, 'P-2', 9000);

                // P-1 は終了日なし＝契約中、P-2 は終了日あり＝解約済み
                return "物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ\n"
                    . self::PROPERTY_NAME . ",P-1,二重太郎,101,2026-04-01,2026-04-15,,8000,8000,,\n"
                    . self::PROPERTY_NAME . ",P-2,二重花子,,2024-04-01,2024-04-15,2025-03-31,9000,,,\n";
        }

        $this->fail("前提のデータが無いタブ: {$method}");
    }

    private function property(?int $totalUnits = null): MsProperty
    {
        return MsProperty::create([
            'property_code' => 'MS-001', 'property_name' => self::PROPERTY_NAME, 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市一番町1-1', 'total_units' => $totalUnits, 'created_by' => $this->user->id,
        ]);
    }

    private function room(MsProperty $property, string $number, string $status): MsRoom
    {
        return MsRoom::create(['property_id' => $property->id, 'room_number' => $number, 'status' => $status]);
    }

    private function tenant(string $name, string $type): MsTenant
    {
        return MsTenant::create(['tenant_type' => $type, 'name' => $name]);
    }

    private function parking(MsProperty $property, string $number, int $fee): MsParking
    {
        return MsParking::create([
            'property_id' => $property->id, 'parking_number' => $number, 'monthly_fee' => $fee,
            'status' => 'vacant', 'has_roof' => false,
        ]);
    }
}
