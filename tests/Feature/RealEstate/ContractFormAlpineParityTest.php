<?php

namespace Tests\Feature\RealEstate;

use Tests\TestCase;

/**
 * 契約の新規登録画面と編集画面で、金額計算の Alpine メソッドが揃っていることを固定する。
 *
 * 2 画面はほぼ同じ計算ロジックを別々に持っている（1 画面 1 Alpine コンポーネントという
 * 既存の流儀に合わせたため）。**片方だけ直して他方を忘れる**のがこのプロジェクトの
 * 典型的な壊れ方で、Bug #35（同一ファイル内の fetch 2 箇所で片方だけヘッダー欠落）と同型。
 *
 * ⚠ 関数本体の文字列一致は見ない。`create` には isBrokerage() ガードがあり `edit` には無いなど
 *    **正当な差異**があるため、文字列一致にすると正当な変更のたびに落ちてテストごと消される。
 *    「メソッドが揃っているか」と「壊れると実害が出るガードが両方にあるか」だけを見る。
 */
class ContractFormAlpineParityTest extends TestCase
{
    private const AMOUNT_METHODS = [
        'hasBuilding',
        'amountOf',
        'taxBp',
        'autoTax',
        'effectiveTax',
        'totalExcl',
        'totalIncl',
        'onBuildingExclInput',
        'onBuildingInclInput',
        'refreshInclusive',
        'money',
        'calcProfit',
    ];

    private function source(string $file): string
    {
        $path = resource_path("views/realestate/contracts/{$file}.blade.php");
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** 金額計算のメソッドが両画面に揃っていること */
    public function test_both_forms_define_the_same_amount_methods(): void
    {
        $create = $this->source('create');
        $edit   = $this->source('edit');

        foreach (self::AMOUNT_METHODS as $method) {
            $this->assertStringContainsString("{$method}: function", $create, "create に {$method} が無い");
            $this->assertStringContainsString("{$method}: function", $edit, "edit に {$method} が無い");
        }
    }

    /**
     * `totalExcl()` の「建物欄が閉じているときは建物の残留 state を無視する」ガードが
     * 両画面にあること。
     *
     * ⚠ これが片方だけ消えると、画面に見えている土地の額・実際に保存される額と
     *    合計表示が食い違う（仕入れ案件フォームで同型の欠陥を実際に踏んだ）。
     */
    public function test_both_forms_guard_total_against_stale_building_state(): void
    {
        foreach (['create', 'edit'] as $file) {
            $this->assertStringContainsString(
                'if (!this.hasBuilding()) { return l; }',
                $this->source($file),
                "{$file} の totalExcl() に建物残留のガードが無い"
            );
        }
    }
}
