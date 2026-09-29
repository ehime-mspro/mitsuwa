<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\TenantImportController;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの CSV 取込（5 タブ）の確定を、確認画面 1 つにつき 1 回だけ通す（設計書 2026-09-28-import-double-submit-design.md）。
 *
 * ⚠ 直す前（2026-09-28 に実測）: 同じ確認画面の確定を 2 回送ると、契約・過去契約は 2 件が 4 件になった。
 *   物件・区画・顧客は重複の確認で 2 回目が「0件を登録しました」になり、取り込み直したように見えた。
 * ⚠ タブは TenantImportController の public な execute{X} を機械的に列挙し、下の TABS と突き合わせる
 *   （新しいタブが増えたら落ちる。コントローラの断りの表にタブが無いと、そのタブの 2 回目が 500 になる）。
 *   タブのキーからは列挙しない（区切りの文字が取込ごとにそろっていない。テナントは past-contract）。
 * ⚠ 同じ利用者で、画面が描いた確定のフォームを 1 回だけ分解して 2 回送る（SubmitsImportPreview::confirm() は
 *   呼ぶたびにプレビューからやり直すので使わない）。
 */
class TenantImportDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;
    use ChecksDoubleSubmit;

    /** execute{X} => [URL のタブ, 数える表, 2 回目の断りの全文（設計書 §4.4）] */
    private const TABS = [
        'executeProperty' => [
            'property', 'properties',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeUnit' => [
            'unit', 'units',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「部屋一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeCustomer' => [
            'customer', 'customers',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeContract' => [
            'contract', 'contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executePastContract' => [
            'past-contract', 'contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「契約一覧」でステータスを「解約済み」にして確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
    ];

    private function importBasePath(): string
    {
        return '/admin/tenant-import';
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
        foreach ((new ReflectionClass(TenantImportController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === TenantImportController::class && preg_match('/^execute[A-Z]/', $method->name) === 1) {
                $methods[] = $method->name;
            }
        }
        sort($methods);

        $classified = array_keys(self::TABS);
        sort($classified);

        $this->assertSame($classified, $methods, 'TABS と TenantImportController の execute{X} がそろっていない（新しいタブは、コントローラの断りの表とこのテストの両方に足す）');
    }

    #[DataProvider('tabs')]
    public function test_sending_the_same_confirmation_twice_imports_once(string $method): void
    {
        [$tab, $table, $message] = self::TABS[$method];
        $csv  = $this->arrange($method);
        $user = $this->executive();
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        $before = DB::table($table)->count();
        $this->send($user, $tab, $form);
        $this->assertSame($before + 2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->send($user, $tab, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertSame($before + 2, DB::table($table)->count(), '2 回目の送信で、もう一度入った');
        $this->assertRefused($second, route('admin.tenant-import', ['tab' => $tab]), $message, $user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $user = $this->executive();
        $form = $this->parseImportForm($this->preview('property', $this->arrange('executeProperty'))->getContent(), 'property');

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->send($user, 'property', $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, Property::count());
        $this->assertRefused($response, route('admin.tenant-import', ['tab' => 'property']), self::TABS['executeProperty'][2], $user);
    }

    public function test_uploading_the_same_file_again_issues_a_new_token(): void
    {
        $csv    = $this->arrange('executeProperty');
        $first  = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');
        $second = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');

        // ⚠ 別のファイルで 2 回プレビューする形では、鍵をファイルの中身から作る書き換え（上げ直しても同じ鍵になり、
        //   12 時間断られる）を見逃す（2026-09-27 の顧客の取込のレビューで実測）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->send($this->executive(), 'property', $second);
        $this->assertSame(2, Property::count(), '上げ直したプレビューの鍵で取り込めない');
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview('property', $this->arrange('executeProperty'))->getContent();

        $this->assertSubmitOnceForm($html, url('/admin/tenant-import/property'));
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function send($user, string $tab, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->from(url($this->importBasePath() . "/{$tab}"))
            ->post($form['action'], $form['fields']);
    }

    /** タブごとの前提のデータを入れ、2 行の CSV を返す（2026-09-28 の実測と同じ中身） */
    private function arrange(string $method): string
    {
        switch ($method) {
            case 'executeProperty':
                return "物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態\n"
                    . "二重ビルA,790-0001,愛媛県松山市一番町1-1,RC造,2000-03,3,自社,,稼働中\n"
                    . "二重ビルB,790-0002,愛媛県松山市二番町2-2,S造,,5,オーナー,大家太郎,稼働中\n";

            case 'executeUnit':
                $this->property();

                return "物件名,階,部屋番号,面積(坪),用途,状態,募集家賃,募集共益費,募集敷金,募集ゴミ代,募集駆除代\n"
                    . "二重ビル,1,A,15.5,,空室,80000,5000,160000,1000,500\n"
                    . "二重ビル,2,B,20.0,,空室,100000,8000,200000,1500,500\n";

            case 'executeCustomer':
                return "テナント名,テナントカナ,種別,代表者名,担当者,電話番号,メールアドレス,郵便番号,住所\n"
                    . "二重商事,ニジュウショウジ,法人,山田太郎,鈴木花子,089-999-9999,info@nijuu.example.jp,790-0002,愛媛県松山市二番町2-2\n"
                    . "二重個人店,,個人事業主,,,,,,\n";

            case 'executeContract':
                $property = $this->property();
                $this->unit($property, 1, 'A');
                $this->unit($property, 2, 'B');
                Customer::create(['code' => 'CU-DS-1', 'name' => '二重商事', 'customer_type' => 'corporation']);
                Customer::create(['code' => 'CU-DS-2', 'name' => '二重個人店', 'customer_type' => 'sole_proprietor']);

                // ⚠ 賃料開始日を埋める（テスト用スキーマの contracts.rent_start_date が NOT NULL。Bug #60）
                return "物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考\n"
                    . "二重ビル,1,A,二重商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,二重商事 松山支店,\n"
                    . "二重ビル,2,B,二重個人店,2026-05-01,2026-05-01,100000,,,,,,\n";

            case 'executePastContract':
                $property = $this->property();
                $this->unit($property, 1, 'A');
                $this->unit($property, 2, 'B');
                // 1 行目の顧客は無い（自動作成される）・2 行目の顧客は既存
                Customer::create(['code' => 'CU-DS-1', 'name' => '二重商事', 'customer_type' => 'corporation']);

                return "物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考\n"
                    . "二重ビル,1,A,過去商事,2020-04-01,2020-04-01,2023-03-31,95000,8000,190000,1500,500,過去商事 松山支店,期間満了で解約\n"
                    . "二重ビル,2,B,二重商事,2019-04-01,2019-04-01,2021-03-31,90000,,,,,,\n";
        }

        $this->fail("前提のデータが無いタブ: {$method}");
    }

    private function property(): Property
    {
        return Property::create([
            'code' => 'T-DS-1', 'name' => '二重ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
    }

    private function unit(Property $property, int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $property->id, 'floor' => $floor, 'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
    }
}
