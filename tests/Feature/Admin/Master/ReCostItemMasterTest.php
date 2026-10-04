<?php

namespace Tests\Feature\Admin\Master;

use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 原価項目マスター（Admin\ReCostItemController）。仕入れ案件・分譲地の原価明細（`re_procurement_costs` /
 * `re_project_costs`）が id で使う。⚠ 本番は両方の明細に外部キー（ON DELETE の指定なし）があるので、
 * 使っている項目を消すと MySQL が断り 500 になる（テストの SQLite は外部キーを張らないので黙って消える）。
 */
class ReCostItemMasterTest extends InlineNameMasterTestCase
{
    use CreatesRealEstateSchema;

    protected function prefix(): string
    {
        return 're-cost-items';
    }

    protected function jsFunction(): string
    {
        return 'costItemManager';
    }

    protected function table(): string
    {
        return 're_cost_items';
    }

    protected function names(): array
    {
        return ['測量費', '造成費', '登記費'];
    }

    protected function maxLength(): int
    {
        return 50;
    }

    protected function createMasterSchema(): void
    {
        $this->createRealEstateSchema();
    }

    protected function markInUse(int $id, string $name): void
    {
        $procurement = ReProcurement::create([
            'procurement_code' => 'P-COST', 'property_type' => 'used_house', 'transaction_type' => 'purchase',
            'status' => 'info_obtained', 'property_name' => '原価テスト', 'address' => '愛媛県松山市1-1', 'created_by' => 1,
        ]);
        DB::table('re_procurement_costs')->insert([
            'procurement_id' => $procurement->id, 'cost_item_id' => $id, 'estimated_amount' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function inUseMessage(string $name): string
    {
        return "「{$name}」は原価明細で使用されているため削除できません。";
    }

    /**
     * G5: 分譲地の原価だけで使っている項目も削除しない。
     * ⚠ 以前は仕入れ案件の原価しか見ておらず、テストでは黙って消え、本番では外部キーに断られて 500 になっていた。
     */
    public function test_an_item_used_only_by_a_project_cost_is_not_deleted(): void
    {
        $project = ReProject::create([
            'project_code' => 'J-COST', 'project_name' => '原価テスト分譲地', 'status' => 'selling',
            'address' => '愛媛県松山市2-2', 'created_by' => 1,
        ]);
        DB::table('re_project_costs')->insert([
            'project_id' => $project->id, 'cost_item_id' => $this->ids['造成費'], 'estimated_amount' => 500000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            'var it = data.items[1]; data.startDelete(it.id, it.name); data.submitDelete();');

        $this->assertTrue(DB::table('re_cost_items')->where('id', $this->ids['造成費'])->exists(), '分譲地の原価で使っている項目が消えた');
        $this->assertFlash($this->landed($sent['response']), 'error', $this->inUseMessage('造成費'));
    }

    // ---- G4: 「物件購入費」は購入価格から自動で計上する項目（同期が名前で引く）なので、名前の変更・削除をさせない ----

    private const PURCHASE_LOCKED_RENAME = '「物件購入費」は仕入れ案件・分譲地の購入価格から自動で計上する項目のため、名前を変更できません。';

    private const PURCHASE_LOCKED_DELETE = '「物件購入費」は仕入れ案件・分譲地の購入価格から自動で計上する項目のため、削除できません。';

    private const PURCHASE_NAME_RESERVED = '「物件購入費」は自動で計上する項目の名前のため、ほかの項目には使えません。';

    /** 購入価格のある仕入れ案件を作る（保存の同期が「物件購入費」の項目と原価行を作る） */
    private function procurementWithPurchase(): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => 'P-BUY', 'property_type' => 'used_house', 'transaction_type' => 'purchase',
            'status' => 'info_obtained', 'property_name' => '物件購入費テスト', 'address' => '愛媛県松山市1-1',
            'assessment_price_land' => 1000000, 'created_by' => 1,
        ]);
    }

    private function purchaseItemId(): int
    {
        return (int) DB::table('re_cost_items')->where('name', '物件購入費')->value('id');
    }

    /** 一覧は「物件購入費」に編集・削除のボタンを出さず、自動で計上する項目だと示す（ほかの項目には出す） */
    public function test_the_list_offers_no_edit_or_delete_for_the_property_purchase_item(): void
    {
        $this->procurementWithPurchase();
        $html = $this->htmlOf($this->indexUrl());

        $this->assertSame(1, preg_match('/<div x-show="([^"]*)"[^>]*>\s*<button @click="startEdit\(item\.id, item\.name\)"/', $html, $buttons),
            '編集・削除のボタンの塊が見つからない');
        $this->assertSame(1, preg_match('/<span x-show="([^"]*)"[^>]*>自動で計上（変更不可）<\/span>/u', $html, $note),
            '自動で計上する項目だという表示が無い');
        $items = $this->driveAlpine($html, $this->jsFunction(), $this->xData($html, $this->jsFunction()), '')['state']['items'];
        $exprs = [];
        foreach (array_keys($items) as $i) {
            $exprs[] = '(function (item) { return ' . html_entity_decode($buttons[1], ENT_QUOTES) . '; })(items[' . $i . '])';
            $exprs[] = '(function (item) { return ' . html_entity_decode($note[1], ENT_QUOTES) . '; })(items[' . $i . '])';
        }
        $shown = $this->driveAlpine($html, $this->jsFunction(), $this->xData($html, $this->jsFunction()), '', [], true, $exprs)['evaluated'];

        foreach ($items as $i => $item) {
            $locked = $item['name'] === '物件購入費';
            $this->assertSame(! $locked, (bool) $shown[$i * 2], "「{$item['name']}」の編集・削除のボタンの出し分けが違う");
            $this->assertSame($locked, (bool) $shown[$i * 2 + 1], "「{$item['name']}」の自動で計上する表示の出し分けが違う");
        }
    }

    /** 手で組んだ送信でも、「物件購入費」の名前の変更と削除は断る（同期が名前で引くので、変えると原価が二重になる） */
    public function test_the_property_purchase_item_is_neither_renamed_nor_deleted(): void
    {
        $procurement = $this->procurementWithPurchase();
        $id = $this->purchaseItemId();

        $rename = $this->actingAs($this->user)->from($this->indexUrl())
            ->put(route('admin.master.re-cost-items.update', $id), ['name' => '物件購入費（土地・建物）']);
        $this->assertFlash($this->landed($rename), 'error', self::PURCHASE_LOCKED_RENAME);
        $delete = $this->actingAs($this->user)->from($this->indexUrl())->delete(route('admin.master.re-cost-items.destroy', $id));
        $this->assertFlash($this->landed($delete), 'error', self::PURCHASE_LOCKED_DELETE);

        $this->assertSame('物件購入費', DB::table('re_cost_items')->where('id', $id)->value('name'));
        $procurement->update(['assessment_price_land' => 2000000]);
        $this->assertSame([2000000], DB::table('re_procurement_costs')->where('procurement_id', $procurement->id)->pluck('estimated_amount')->map(fn ($v) => (int) $v)->all(),
            '物件購入費の原価行が 1 行でない');
    }

    /** ほかの項目を「物件購入費」という名前で追加・変更することもできない（同期が 2 つの項目のどちらを引くか決まらなくなる） */
    public function test_no_other_item_may_take_the_property_purchase_name(): void
    {
        $this->procurementWithPurchase();

        // ⚠ 送ったらすぐに着いた画面を見る（次の画面を開くと、差し戻しのエラーをそこで使い切る。Bug #49）
        $add = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "data.startAdd(); data.newName = '物件購入費'; data.submitAdd();");
        $this->assertStringContainsString('<p class="text-sm text-red-800">' . self::PURCHASE_NAME_RESERVED . '</p>', $this->landed($add['response']));

        $rename = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "var it = data.items.find(function (i) { return i.name === '測量費'; }); data.startEdit(it.id, it.name); data.editingName = '物件購入費'; data.submitEdit();");
        $this->assertStringContainsString('<p class="text-sm text-red-800">' . self::PURCHASE_NAME_RESERVED . '</p>', $this->landed($rename['response']));

        $this->assertSame(1, DB::table('re_cost_items')->where('name', '物件購入費')->count());
        $this->assertSame('測量費', DB::table('re_cost_items')->where('id', $this->ids['測量費'])->value('name'));
    }
}
