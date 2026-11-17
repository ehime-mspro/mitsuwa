<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Support\RealEstateCostGrid;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 新規登録（仕入れ案件・分譲地）の原価欄を、見込み額・確定額の表で入れる（設計書 8 章）。
 *
 * ⚠ 新規登録の表はサーバーに送らない。「表を閉じる」（closeCostGrid）で一覧（costs）に入れ、一覧の hidden
 *   （costs[N][…]）が「登録する」で送られる。ここでは画面に描いた `<script>`（costSectionFormController と
 *   costGridFactory）を node で動かし、ブラウザが送る hidden を組んで登録まで往復する（Bug #47）。
 * ⚠ 表を開いたまま「登録する」（Enter で送った場合も）は止める（打った値を黙って捨てない。Review Focus 3）。
 * ⚠ 表の行は費用項目マスタの順: 仲介手数料（0）・物件購入費（1・新規登録の表には出さない）・測量費（2）・その他（3）。
 */
class CostGridCreateFormTest extends RealEstateScreenTestCase
{
    private const SCRIPTS = ['function costExcelImporterFactory(', 'function costGridFactory('];

    private const OPEN_MESSAGE = '原価の表を開いたままです。「表を閉じる」を押してから登録してください。';

    private const CLOSED_MESSAGE = '表の内容を一覧に入れました。「登録する」を押すまで保存されません。';

    /** 一覧（costs）の行を [項目名, 見込み額, 確定額, 備考] で返す式 */
    private const LIST = 'costs.map(function (c) { return [c.cost_item_name, c.estimated_amount, c.actual_amount, c.notes]; })';

    private ReCostItem $brokerItem;

    private ReCostItem $otherItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brokerItem = ReCostItem::create(['name' => '仲介手数料', 'sort_order' => 0]);
        $this->otherItem = ReCostItem::create(['name' => 'その他', 'sort_order' => 3]);
    }

    public static function screens(): array
    {
        return ['仕入れ案件' => ['procurement'], '分譲地' => ['project']];
    }

    // ============================================================
    // 助け
    // ============================================================

    private function createUrl(string $kind): string
    {
        return route("realestate.{$kind}s.create");
    }

    /** 登録画面のフォーム（原価欄は $costSteps で動かす。仕入れ案件は物件種別を選ぶ） */
    private function form(string $kind, string $html, string $costSteps): array
    {
        return $kind === 'procurement'
            ? $this->formWithCostSection($html, route('realestate.procurements.store'), 'procurementForm', 'data.propertyType = "used_house";', $costSteps)
            : $this->formWithCostSection($html, route('realestate.projects.store'), 'projectForm', '', $costSteps);
    }

    /** 利用者が打つ必須の欄（$name は物件名・分譲地名。空にすると入力エラーで戻る） */
    private function filled(string $kind, array $form, string $name): array
    {
        return $kind === 'procurement'
            ? $this->fill($form, ['transaction_type' => 'purchase', 'status' => 'info_obtained', 'property_name' => $name, 'address' => '愛媛県松山市道後町1-1'])
            : $this->fill($form, ['project_name' => $name, 'status' => 'info_obtained', 'address' => '愛媛県松山市北条1']);
    }

    /** 原価欄のコンポーネントを動かす（PHPUnit の TestCase の run() は final なので drive） */
    private function drive(string $html, string $steps, array $evaluate = []): array
    {
        return $this->driveAlpine($html, 'costSectionFormController', 'costSectionFormController()', $steps, [], true, $evaluate, self::SCRIPTS);
    }

    /** 表の行（$name の $n 番目）を指す JS の式 */
    private function row(string $name, int $n = 0): string
    {
        return 'data.costGrid.rows.filter(function (r) { return r.name === ' . json_encode($name, JSON_UNESCAPED_UNICODE) . "; })[{$n}]";
    }

    /** 表の行の金額（と備考）を打つ JS */
    private function type(string $name, string $amount, ?string $notes = null): string
    {
        $js = 'var r = ' . $this->row($name) . '; r.draft.amount = ' . json_encode($amount, JSON_UNESCAPED_UNICODE) . ';';
        if ($notes !== null) {
            $js .= ' r.draft.notes = ' . json_encode($notes, JSON_UNESCAPED_UNICODE) . ';';
        }

        return $js;
    }

    /** 一覧に行を 1 つ入れる JS（Excel 取込・入力エラーで戻ったときと同じ形） */
    private function listed(ReCostItem $item, int $estimated, ?int $actual = null, string $notes = ''): string
    {
        return 'data.costs.push(' . json_encode([
            'cost_item_id'         => $item->id,
            'cost_item_name'       => $item->name,
            'estimated_amount'     => $estimated,
            'actual_amount'        => $actual,
            'notes'                => $notes,
            'is_property_purchase' => false,
        ], JSON_UNESCAPED_UNICODE) . ');';
    }

    /** 見込み額の表で測量費を入れて閉じ、確定額の表で確定額を入れて閉じる（登録の往復と入力エラーのテストで共用） */
    private function surveyThroughBothGrids(): string
    {
        return 'data.openCostGrid("estimated"); ' . $this->type('測量費', '300,000', '境界確定') . ' data.closeCostGrid();'
            . ' data.openCostGrid("actual"); ' . $this->type('測量費', '280000') . ' data.closeCostGrid();';
    }

    private function surveyHidden(string $estimated, string $actual, string $notes): array
    {
        return ['cost_item_id' => (string) $this->surveyItem->id, 'estimated_amount' => $estimated, 'actual_amount' => $actual, 'notes' => $notes];
    }

    // ============================================================
    // 表で入れて登録する
    // ============================================================

    #[DataProvider('screens')]
    public function test_rows_from_the_grid_are_registered_with_the_procurement_or_project(string $kind): void
    {
        $url = $this->createUrl($kind);
        $form = $this->filled($kind, $this->form($kind, $this->htmlOf($url), $this->surveyThroughBothGrids()), '道後');

        $this->assertSame([$this->surveyHidden('300000', '280000', '境界確定')], $form['fields']['costs'] ?? null,
            '表で入れた行が、一覧の hidden（costs[0][…]）に載っていない');

        $html = $this->landed($this->submit($form, $url));

        $label = $kind === 'procurement' ? '仕入れ案件「RE-PRC-001」' : '分譲地「RE-PRJ-001」';
        $this->assertFlash($html, 'success', $label . 'を登録しました（原価 1 件を含む）。');
        $query = $kind === 'procurement' ? ReProcurementCost::query() : ReProjectCost::query();
        $cost = $query->where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([300000, 280000, '境界確定'], [$cost->estimated_amount, $cost->actual_amount, $cost->notes]);
    }

    #[DataProvider('screens')]
    public function test_the_create_grid_has_no_property_purchase_row(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $names = 'costGrid.rows.map(function (r) { return r.name + (r.auto ? "/自動" : ""); })';

        foreach (['estimated', 'actual'] as $mode) {
            $run = $this->drive($html, 'data.openCostGrid("' . $mode . '");', [$names, 'costGrid.mode']);
            $this->assertSame([['仲介手数料', '測量費', 'その他'], $mode], $run['evaluated'],
                "新規登録の{$mode}の表に物件購入費の行が出ている（登録したあとに査定価格・購入価格から自動で入る）か、表が開いていない");
        }
    }

    #[DataProvider('screens')]
    public function test_an_actual_only_row_is_listed_with_a_zero_estimate(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $steps = 'data.openCostGrid("actual"); ' . $this->type('測量費', '750,000') . ' data.closeCostGrid();';

        $run = $this->drive($html, $steps, [self::LIST, 'costGrid.mode', 'costMessage']);
        $this->assertSame([[['測量費', 0, 750000, '']], null, self::CLOSED_MESSAGE], $run['evaluated']);

        $form = $this->form($kind, $html, $steps);
        $this->assertSame([$this->surveyHidden('0', '750000', '')], $form['fields']['costs'] ?? null);
    }

    // ============================================================
    // 一覧の行を表で直す・外す
    // ============================================================

    #[DataProvider('screens')]
    public function test_clearing_an_estimate_removes_the_row_from_the_list(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $steps = $this->listed($this->surveyItem, 300000)
            . ' data.openCostGrid("estimated"); ' . $this->type('測量費', '') . ' data.closeCostGrid();';

        $run = $this->drive($html, $steps, [self::LIST, 'costGrid.mode', 'costMessage']);

        $this->assertSame([], $run['confirms'], '新規登録の表で行を外すのに確かめが出た（保存済みの行が無いので確かめない）');
        $this->assertSame([], $run['alerts']);
        $this->assertSame([[], null, self::CLOSED_MESSAGE], $run['evaluated']);
    }

    /**
     * 表の行は開いたときの一覧の番号（ref）を持つ。直す行はその番号の行に当て、外す行は番号の大きい順に外し、
     * 新しい行は一覧の末尾に足す（外してから直すと、番号がずれて別の行を書き換える）。
     */
    #[DataProvider('screens')]
    public function test_changes_are_applied_to_the_list_rows_they_came_from(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $steps = $this->listed($this->surveyItem, 300000, null, '旧')
            . ' ' . $this->listed($this->otherItem, 50000)
            . ' ' . $this->listed($this->brokerItem, 80000)
            . ' data.openCostGrid("estimated"); ' . $this->type('測量費', '') . ' ' . $this->type('仲介手数料', '')
            . ' ' . $this->type('その他', '60,000', '追加工事') . ' data.closeCostGrid();'
            . ' data.openCostGrid("actual"); ' . $this->type('仲介手数料', '1000') . ' ' . $this->type('その他', '55000') . ' data.closeCostGrid();';

        $run = $this->drive($html, $steps, [self::LIST, 'costGrid.mode']);

        $this->assertSame([[
            ['その他', 60000, 55000, '追加工事'],
            ['仲介手数料', 0, 1000, ''],
        ], null], $run['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_clearing_an_estimate_with_an_actual_is_refused(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $steps = $this->listed($this->surveyItem, 300000, 280000)
            . ' data.openCostGrid("estimated"); ' . $this->type('測量費', '') . ' data.closeCostGrid();';

        $run = $this->drive($html, $steps, [
            'costGrid.mode',
            'costGridRowError(' . $this->row('測量費') . ')',
            'costGrid.error',
            self::LIST,
            'costMessage',
        ]);

        $this->assertSame([
            'estimated',
            RealEstateCostGrid::MESSAGES['hasActual'],
            '保存できませんでした。赤い行を直してください（1 件）。何も保存していません。',
            [['測量費', 300000, 280000, '']],
            '',
        ], $run['evaluated'], '確定額のある行の見込み額を空にしたのに、表が閉じたか、一覧から行が消えた（確定額を黙って捨てる）');
    }

    // ============================================================
    // 表を開いたまま登録する
    // ============================================================

    #[DataProvider('screens')]
    public function test_registering_with_the_grid_open_is_stopped(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        // submit の出来事の偽物（ブラウザでは e.target は送られたフォーム。原価欄を含むかを querySelector で見る）
        $steps = 'data.__prevented = []; data.__selectors = [];'
            . ' var send = function (label, containsSection) { data.costGridGuardSubmit({'
            . '   target: { querySelector: function (s) { data.__selectors.push(s); return containsSection ? {} : null; } },'
            . '   preventDefault: function () { data.__prevented.push(label); } }); };'
            . ' send("閉じている", true);'
            . ' data.openCostGrid("estimated"); ' . $this->type('測量費', '300,000')
            . ' send("ほかのフォーム", false);'
            . ' send("開いている", true);';

        $run = $this->drive($html, $steps, [
            '__prevented',
            'costGrid.error',
            'costGrid.mode',
            $this->row('測量費') . '.draft.amount',
            'costs.length',
            '__selectors',
        ]);
        $selectors = array_pop($run['evaluated']);

        $this->assertSame([['開いている'], self::OPEN_MESSAGE, 'estimated', '300,000', 0], $run['evaluated'],
            '表を開いたままの「登録する」を止めていないか、閉じているとき・原価欄を含まないフォームを止めている');
        $this->assertSame([self::OPEN_MESSAGE], $run['alerts']);
        $this->assertNotSame([], $selectors);
        $this->assertSame(['[data-cost-section]'], array_values(array_unique($selectors)), '送られたフォームが原価欄を含むかを [data-cost-section] で見ていない');
    }

    // ============================================================
    // 入力エラーで戻ったとき
    // ============================================================

    #[DataProvider('screens')]
    public function test_old_costs_come_back_into_the_grid_after_an_input_error(string $kind): void
    {
        $url = $this->createUrl($kind);
        $form = $this->filled($kind, $this->form($kind, $this->htmlOf($url), $this->surveyThroughBothGrids()), '');

        $html = $this->landed($this->submit($form, $url));
        $this->assertSame(0, $kind === 'procurement' ? ReProcurement::count() : ReProject::count(), '物件名・分譲地名が空なのに登録された');

        $row = $this->row('測量費');
        $run = $this->drive($html, 'data.openCostGrid("estimated");', [
            self::LIST,
            $row . '.ref',
            $row . '.draft.amount',
            $row . '.draft.notes',
            $row . '.orig.act',
        ]);
        $this->assertSame([[['測量費', 300000, 280000, '境界確定']], 0, '300,000', '境界確定', 280000], $run['evaluated'],
            '入力エラーで戻った画面の一覧と表に、送った原価（old(\'costs\')）が入っていない');

        $again = $this->form($kind, $html, '');
        $this->assertSame([$this->surveyHidden('300000', '280000', '境界確定')], $again['fields']['costs'] ?? null);
    }

    // ============================================================
    // 部品の形
    // ============================================================

    #[DataProvider('screens')]
    public function test_the_section_guards_the_surrounding_form(string $kind): void
    {
        $html = $this->htmlOf($this->createUrl($kind));
        $open = '<div x-data="costSectionFormController()"';
        $start = strpos($html, $open);
        $this->assertNotFalse($start, '登録画面に原価欄が無い');
        $root = substr($html, $start, strpos($html, '>', $start) - $start + 1);
        $section = $this->balancedElement($html, $start, 'div');

        // 根で submit を見張る（e.target.querySelector('[data-cost-section]') で、この原価欄を含むフォームだけを止める）
        $this->assertMatchesRegularExpression('/\sdata-cost-section[\s>]/', $root, '原価欄の根に data-cost-section が無い');
        $this->assertStringContainsString('@submit.window="costGridGuardSubmit($event)"', $root, '原価欄の根で submit を見張っていない');
        $withoutScripts = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $this->assertSame(1, preg_match_all('/\sdata-cost-section[\s>]/', $withoutScripts), 'data-cost-section が原価欄の根のほかにも付いている');

        // 原価欄は登録のフォームの中にある（送られるフォームが原価欄を含む）
        $store = route("realestate.{$kind}s.store");
        $formStart = strpos($html, '<form method="POST" action="' . $store . '"');
        $this->assertNotFalse($formStart, '登録のフォームが無い');
        $this->assertStringContainsString($section, $this->balancedElement($html, $formStart, 'form'), '原価欄が登録のフォームの外にある');

        // 右上のボタン（表と Excel 取込を同時に開かない）と、表の下の「表を閉じる」
        $hidden = 'x-show="costGrid.mode === null && !costExcelImport.open"';
        foreach (["openCostExcelImport()" => '📂 試算表 Excel 取込', "openCostGrid('estimated')" => '見込み額を入力', "openCostGrid('actual')" => '確定額を入力'] as $call => $label) {
            $pattern = '/<button\b[^>]*@click="' . preg_quote($call, '/') . '"[^>]*' . preg_quote($hidden, '/') . '[^>]*>\s*' . preg_quote($label, '/') . '\s*<\/button>/u';
            $this->assertSame(1, preg_match_all($pattern, $section), "「{$label}」のボタンが無いか、表・取込を開いているあいだも出ている");
        }
        $this->assertSame(1, preg_match_all('/<button\b[^>]*@click="closeCostGrid\(\)"[^>]*>\s*表を閉じる\s*<\/button>/u', $section), '「表を閉じる」のボタンが無い');
        $this->assertStringNotContainsString('saveCostGrid()', $section, '新規登録の表に保存のボタンがある（新規登録は送らない）');

        // 外したもの
        $this->assertStringNotContainsString('＋ 費用追加', $html);
        foreach (['showAddCost', 'newCost', 'addCost(', 'resetNewCost'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "外したはずの「{$gone}」が残っている");
        }

        // 表を開いているあいだは一覧を隠す（一覧の「削除」で番号がずれると、表を閉じたときに別の行を書き換える）
        $this->assertSame(1, preg_match('/<div class="border border-gray-200 rounded-md overflow-hidden" x-show="costGrid\.mode === null">/', $section),
            '表を開いているあいだも一覧が出ている');
        $this->assertStringContainsString('原価データがありません。「見込み額を入力」から入れてください。', $section);
        $this->assertStringContainsString('「見込み額を入力」「確定額を入力」で表を開いて入れ、「表を閉じる」で一覧に入れます。登録するまで保存されません。物件購入費は仕入れ情報から自動同期されます。', $section);
    }
}
