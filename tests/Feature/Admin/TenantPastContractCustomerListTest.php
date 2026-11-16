<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの過去契約の取込で、確認画面の「顧客 N件を自動作成: 名前…」は、取り込む行の顧客だけを数える。
 *
 * ⚠ 直す前（2026-11-16 に使い捨てのテストで実測）: 顧客がマスタに無いと分かった時点（行の検査の途中）で名前を積んでいたので、
 *   そのあとの検査（金額・文字数）で誤りになる行のテナント名も載った（確認画面「顧客 4件」・確定「顧客 1件」）。
 *   確定はもともと取り込む行から顧客を作るので正しかった。
 * ⚠ 役割（viewData('summary')）と表示（画面の文字）を別々に見る（Bug #43 / #54 ④）。確定はプレビューが描いたフォームを
 *   そのまま送り返す往復（SubmitsImportPreview）。
 * 設計: docs/superpowers/specs/2026-11-16-past-contract-create-list-design.md
 */
class TenantPastContractCustomerListTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::create([
            'code' => 'T-AC-1', 'name' => '自動作成ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $room) {
            Unit::create([
                'property_id' => $property->id, 'floor' => 1, 'room_number' => $room,
                'display_name' => Unit::generateDisplayName(1, $room), 'status' => 'vacant', 'area_tsubo' => 10,
            ]);
        }
        Customer::create(['code' => 'CU-AC-1', 'name' => '既存商事', 'customer_type' => 'corporation']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /** 過去契約の 1 行（区画 1 階・契約日 2020-04-01・解約日 2023-03-31）。家賃・屋号を変えられる */
    private function row(string $room, string $name, string $rent = '90000', string $storeName = ''): string
    {
        return "自動作成ビル,1,{$room},{$name},2020-04-01,,2023-03-31,{$rent},,,,,{$storeName},\n";
    }

    public function test_names_of_rows_that_fail_later_checks_are_not_counted(): void
    {
        $longName = str_repeat('い', 201);
        $csv = self::PAST_HEADER . "\n"
            . $this->row('A', '作られる商事')
            . $this->row('B', '金額誤り商事', 'abc')
            . $this->row('C', $longName)
            . $this->row('D', '屋号長い商事', '90000', str_repeat('う', 201))
            . $this->row('E', '既存商事');
        $summary = '過去契約 2件を新規作成（顧客 1件を自動作成: 作られる商事）';

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $html = $preview->getContent();

        // 役割: 誤りの 3 行は取り込まず、自動作成の予定にも入らない（既存の顧客の行も入らない）
        $this->assertSame([3, 4, 5], array_column($preview->viewData('rowErrors'), 'row'));
        $this->assertSame($summary, $preview->viewData('summary'));
        // 表示: 「作成予定」に全文。誤りの行の名前はどこにも出ない（誤りの文は名前を含まない）
        $this->assertStringContainsString($summary, $html);
        $this->assertStringNotContainsString('金額誤り商事', $html);
        $this->assertStringNotContainsString('屋号長い商事', $html);
        $this->assertStringNotContainsString($longName, $html);

        // 確定の完了の文と、実際に作られた顧客が確認画面と同じ
        $this->confirm('past-contract', $csv)
            ->assertSessionHas('success', '過去契約インポート完了: 契約 2件を登録、顧客 1件を自動作成');
        $this->assertSame(['既存商事', '作られる商事'], Customer::orderBy('id')->pluck('name')->all());
        $this->assertSame(2, Contract::count());
    }

    /** 直しすぎを止める守り: 先の行が誤りでも、あとの行が同じ名前で取り込まれるなら載る（同じ名前は 1 回だけ） */
    public function test_a_name_whose_later_row_is_imported_is_counted_once(): void
    {
        $csv = self::PAST_HEADER . "\n"
            . $this->row('A', '後で入る商事', 'abc')
            . $this->row('B', '後で入る商事')
            . $this->row('C', '後で入る商事')
            . $this->row('D', '二つ目商事');
        $summary = '過去契約 3件を新規作成（顧客 2件を自動作成: 後で入る商事、二つ目商事）';

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $this->assertSame([2], array_column($preview->viewData('rowErrors'), 'row'));
        $this->assertSame($summary, $preview->viewData('summary'));
        $this->assertStringContainsString($summary, $preview->getContent());

        $this->confirm('past-contract', $csv)
            ->assertSessionHas('success', '過去契約インポート完了: 契約 3件を登録、顧客 2件を自動作成');
        $this->assertSame(1, Customer::where('name', '後で入る商事')->count());
        $this->assertSame(3, Contract::count());
    }

    /** 6 件以上は先頭の 5 件と「 ...」。誤りの行の名前が 5 件の中に入り込まない */
    public function test_only_the_first_five_imported_names_are_listed(): void
    {
        $csv = self::PAST_HEADER . "\n"
            . $this->row('A', 'あ商事')
            . $this->row('G', '誤り商事', 'abc')
            . $this->row('B', 'い商事')
            . $this->row('C', 'う商事')
            . $this->row('D', 'え商事')
            . $this->row('E', 'お商事')
            . $this->row('F', 'か商事');
        $summary = '過去契約 6件を新規作成（顧客 6件を自動作成: あ商事、い商事、う商事、え商事、お商事 ...）';

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $this->assertSame([3], array_column($preview->viewData('rowErrors'), 'row'));
        $this->assertSame($summary, $preview->viewData('summary'));
        $this->assertStringContainsString($summary, $html);
        $this->assertStringNotContainsString('誤り商事', $html);
    }
}
