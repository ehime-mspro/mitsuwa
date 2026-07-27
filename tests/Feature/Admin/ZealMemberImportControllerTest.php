<?php

namespace Tests\Feature\Admin;

use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesZealSchema;
use Tests\TestCase;

/**
 * ZEAL 会員 CSV インポート（Admin\ZealMemberImportController）の Feature テスト。
 * zeal_* テーブルは migration 管理外のため CreatesZealSchema trait で構築する。
 */
class ZealMemberImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createZealSchema();
    }

    /** 新フォーマットの 77 列ヘッダー（サンプル CSV 準拠。次回課金予定日は仕様通り重複あり） */
    private const HEADERS = [
        'ID','状態','定期購入','独自会員ID','メールアドレス','名前','名前カナ','見出し','電話番号','郵便番号',
        '住所','身長','性別','生年月日','年齢','カスタム1','カスタム2','カスタム3','カスタム4','カスタム5',
        'カスタム6','カスタム7','カスタム8','カスタム9','カスタム10','緊急連絡先(続柄)','緊急連絡先(名前)','緊急連絡先(電話番号)','初回セッション日','体験利用日',
        '入会日','利用開始日','コース変更日(予約ルール)','コース変更日(課金設定)','退会予定日','退会日','システム外予約数','システム外売上','紹介コード','tracking_code',
        '顧客内部カルテ','顧客共有ノート','コースマスタ連動','次回課金予定日','チケットルール','残チケット数','残チケット数(来月)','残チケット数(再来月)','pay_customer_id','残ポイント数',
        '合計金額(初回)','合計金額(2回目以降)','決済エラー','課金済み月','次回課金予定日','作成日','金融機関番号','金融機関名','支店番号','支店名',
        '口座種別','口座番号','口座名義','店舗 ID','店舗 名前','支払手段','次回課金予定日','コース ID','コース 名前','コース 名前（内部）',
        'コース 合計金額(初回)','コース 合計金額(2回目以降)','変更後コース ID','変更後コース 名前','変更後コース 名前（内部）','変更後コース 合計金額(初回)','変更後コース 合計金額(2回目以降)',
    ];

    /** password.change を通過する経営層ユーザー */
    private function executive(): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プラン・店舗マスタを seed（fixture が参照する 2 プラン + 既定店舗） */
    private function seedMasters(): void
    {
        ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        ZealPlan::create(['name' => 'パーソナル&セミパーソナル月4回', 'regular_price_excl' => 13000]);
    }

    /** 使用列のみ持つ 1 行（他列は空）。$over で上書き */
    private function baseRow(array $over = []): array
    {
        return array_merge([
            'ID' => 'CL00000000', '状態' => '会員', '定期購入' => 'TRUE',
            '名前' => '', '名前カナ' => '', '性別' => '男性', '生年月日' => '1990/1/2',
            'カスタム2' => '（新）セミパーソナル通い放題', '入会日' => '2025/10/17',
            '退会予定日' => '', '退会日' => '', '紹介コード' => '', '顧客内部カルテ' => '',
            '残チケット数' => '0', '合計金額(2回目以降)' => '9702',
            '店舗 名前' => 'ZEAL BOXING FITNESS 松山市駅前店',
            'コース 名前' => '（新）セミパーソナル通い放題', 'コース 合計金額(2回目以降)' => '10780',
            '変更後コース 名前' => '',
        ], $over);
    }

    /** 5 区分 + ビジター(除外) + 氏名空(エラー) の 7 行を持つ標準 fixture */
    private function fixtureRows(): array
    {
        return [
            $this->baseRow(['ID' => 'CL001', '名前' => '在籍 太郎', '名前カナ' => 'ザイセキ タロウ', '入会日' => '2025/10/17', '合計金額(2回目以降)' => '9702']),
            $this->baseRow(['ID' => 'CL002', '状態' => '停止中', '名前' => '退会 花子', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '', '合計金額(2回目以降)' => '0', '退会日' => '2026/6/1', '定期購入' => 'FALSE', '入会日' => '2024/4/1']),
            $this->baseRow(['ID' => 'CL003', '名前' => '休会 次郎', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '休会プラン', '合計金額(2回目以降)' => '1100', '入会日' => '2025/1/10']),
            $this->baseRow(['ID' => 'CL004', '名前' => '券 三郎', '定期購入' => 'FALSE', 'カスタム2' => '', 'コース 名前' => 'チケット会員', '合計金額(2回目以降)' => '0', '残チケット数' => '4', '入会日' => '2025/3/3']),
            $this->baseRow(['ID' => 'CL005', '名前' => '休眠 四郎', '定期購入' => 'FALSE', 'カスタム2' => 'パーソナル&セミパーソナル月4回（松山市駅前）', 'コース 名前' => 'パーソナル&セミパーソナル月4回（松山市駅前）', '合計金額(2回目以降)' => '0', '入会日' => '2025/5/5']),
            $this->baseRow(['ID' => 'CL006', '状態' => 'ビジター', '名前' => '見学 五郎', '入会日' => '2026/2/2']),
            $this->baseRow(['ID' => 'CL007', '名前' => '', '入会日' => '2025/7/7']),
        ];
    }

    /** 行配列 → 77 列 CSV 文字列 */
    private function csvContent(array $rows): string
    {
        $lines = [implode(',', self::HEADERS)];
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::HEADERS as $h) {
                $cells[] = '"' . str_replace('"', '""', (string) ($row[$h] ?? '')) . '"';
            }
            $lines[] = implode(',', $cells);
        }
        return implode("\n", $lines);
    }

    /** CSV 文字列を mimes:csv を通る UploadedFile にする */
    private function uploadFrom(string $content): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zealcsv') . '.csv';
        file_put_contents($path, $content);
        return new \Illuminate\Http\UploadedFile($path, 'members.csv', 'text/csv', null, true);
    }

    public function test_execute_imports_each_kind_and_excludes_visitor(): void
    {
        $this->seedMasters();
        $content = $this->csvContent($this->fixtureRows());

        $response = $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.execute'), [
                'confirmed' => '1',
                'csv_data'  => base64_encode($content),
            ]);

        $response->assertRedirect(route('admin.zeal.member-import'));
        $response->assertSessionHas('success', 'インポート完了: 登録 5件 / スキップ 0件 / エラー 1件 / 除外 1件');

        // 5 区分が会員化（ビジター除外・エラー行スキップ）
        $this->assertDatabaseCount('zeal_members', 5);
        // 契約はチケットを除く 4 件
        $this->assertDatabaseCount('zeal_member_contracts', 4);

        // 退会済: withdrew_on と契約 period_end が入る（date cast 経由で検証）
        $withdrawn = ZealMember::where('name', '退会 花子')->first();
        $this->assertNotNull($withdrawn);
        $this->assertSame('2026-06-01', $withdrawn->withdrew_on?->format('Y-m-d'));
        $wc = ZealMemberContract::where('member_id', $withdrawn->id)->first();
        $this->assertNotNull($wc);
        $this->assertSame('2026-06-01', $wc->period_end?->format('Y-m-d'));

        // チケット: current_plan_id は null かつ契約なし
        $ticket = ZealMember::where('name', '券 三郎')->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->current_plan_id);
        $this->assertSame(0, ZealMemberContract::where('member_id', $ticket->id)->count());
    }

    public function test_preview_classifies_all_kinds(): void
    {
        $this->seedMasters();
        $content = $this->csvContent($this->fixtureRows());

        $response = $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.preview'), [
                'csv_file' => $this->uploadFrom($content),
            ]);

        $response->assertOk(); // 新 Blade が描画できること（Bug #26 型の検出も兼ねる）

        // 区分ごとの件数（view data で厳密検証）
        $this->assertCount(5, $response->viewData('toImport'));
        $this->assertCount(1, $response->viewData('errored'));
        $this->assertCount(1, $response->viewData('excluded'));
        $this->assertCount(0, $response->viewData('skipped'));

        // 画面に区分ラベルと氏名が出ること
        $response->assertSee('在籍');
        $response->assertSee('退会済');
        $response->assertSee('休会');
        $response->assertSee('チケット');
        $response->assertSee('定期OFF');
        $response->assertSee('在籍 太郎');
        $response->assertSee('券 三郎');
        $response->assertSee('（氏名なし）');   // エラー行(氏名空)の表示
        $response->assertSee('氏名が空です');   // エラー行のエラーメッセージ描画
    }

    /** 本部テスト用アカウント（2026-07-26 に本番へ混入した実データと同形） */
    private function testAccountRow(): array
    {
        return $this->baseRow([
            'ID' => 'CL23326867', '名前' => 'MS 二宮／テスト', '性別' => '女性',
            'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '',
            '定期購入' => 'FALSE', '合計金額(2回目以降)' => '0', '入会日' => '2026/3/10',
        ]);
    }

    public function test_headquarters_test_account_is_not_imported(): void
    {
        $this->seedMasters();
        $rows   = $this->fixtureRows();
        $rows[] = $this->testAccountRow();
        $content = $this->csvContent($rows);

        $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.execute'), [
                'confirmed' => '1', 'csv_data' => base64_encode($content),
            ])
            // ビジター 1 + テストアカウント 1 = 除外 2
            ->assertSessionHas('success', 'インポート完了: 登録 5件 / スキップ 0件 / エラー 1件 / 除外 2件');

        $this->assertDatabaseCount('zeal_members', 5);
        $this->assertSame(0, ZealMember::where('name', 'MS 二宮／テスト')->count());
    }

    public function test_preview_shows_why_a_test_account_was_excluded(): void
    {
        $this->seedMasters();
        $rows   = $this->fixtureRows();
        $rows[] = $this->testAccountRow();

        $response = $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.preview'), [
                'csv_file' => $this->uploadFrom($this->csvContent($rows)),
            ]);

        $response->assertOk();
        $this->assertCount(2, $response->viewData('excluded'));

        // 状態が「会員」のまま除外されるので、理由が出ないと利用者が誤解する
        $response->assertSee('MS 二宮／テスト');
        $response->assertSee('本部テスト用アカウント');
    }

    public function test_execute_skips_existing_duplicate(): void
    {
        $this->seedMasters();
        ZealMember::create([
            'store_id' => ZealStore::first()->id, 'name' => '在籍 太郎',
            'joined_on' => '2025-10-17', 'created_by' => 1, 'updated_by' => 1,
        ]);

        $content = $this->csvContent($this->fixtureRows());
        $this->actingAs($this->executive())
            ->post(route('admin.zeal.member-import.execute'), [
                'confirmed' => '1', 'csv_data' => base64_encode($content),
            ])->assertRedirect(route('admin.zeal.member-import'));

        // 事前 1 + 取込 4（在籍は重複スキップ）= 5
        $this->assertDatabaseCount('zeal_members', 5);
        // 取込 4 のうちチケットを除く 3 件が契約
        $this->assertDatabaseCount('zeal_member_contracts', 3);
    }

    public function test_preview_rejects_wrong_format(): void
    {
        $this->seedMasters();
        $wrong = "状態,入会日\n会員,2025/10/17\n"; // 名前 列が無い

        $this->actingAs($this->executive())
            ->from(route('admin.zeal.member-import'))
            ->post(route('admin.zeal.member-import.preview'), [
                'confirmed' => '1', 'csv_data' => base64_encode($wrong),
            ])
            ->assertRedirect(route('admin.zeal.member-import'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('zeal_members', 0);
    }

    public function test_non_executive_is_forbidden(): void
    {
        $manager = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Manager->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($manager)
            ->get(route('admin.zeal.member-import'))
            ->assertForbidden();
    }

    public function test_index_shows_new_format_guidance_without_template_link(): void
    {
        $response = $this->actingAs($this->executive())
            ->get(route('admin.zeal.member-import'));

        $response->assertOk();
        $response->assertSee('会員管理システム');         // 新フォーマット説明
        $response->assertDontSee('サンプルCSVをダウンロード'); // 旧テンプレDLは撤去
    }

    public function test_template_route_is_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.zeal.member-import.template'));

        $this->actingAs($this->executive())
            ->get('/admin/zeal/member-import/template')
            ->assertNotFound();
    }

    public function test_zeal_schema_is_usable(): void
    {
        $store = ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        $plan  = ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        $member = ZealMember::create([
            'store_id' => $store->id, 'name' => '健全 太郎', 'joined_on' => '2025-10-17',
            'current_plan_id' => $plan->id, 'created_by' => 1, 'updated_by' => 1,
        ]);

        $this->assertDatabaseCount('zeal_members', 1);
        $this->assertSame('健全 太郎', $member->fresh()->name);
    }
}
