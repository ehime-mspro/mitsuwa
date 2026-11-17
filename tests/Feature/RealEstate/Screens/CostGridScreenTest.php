<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use App\Models\User;
use App\Support\RealEstateCostGrid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 原価の入力の表（見込み額・確定額を表で打つ）を、詳細画面の JS を動かして見る（仕入れ案件・分譲地で同じ）。
 *
 * ⚠ 要求は手で組まない。画面に描いた `<script>`（costGridFactory とホスト）を node で動かし、JS が組んだ要求を
 *   そのまま送って、応答をもう一度 JS に戻して画面の状態まで見る（Bug #35 / #47）。
 * ⚠ 断る場面は「要求が 0 件」まで見る（画面で断れば何も送らない）。サーバーの同じ断りは CostGridSaveTest が見る。
 * ⚠ 表の行は費用項目マスタの順（`ReCostItem::forCostGrid()`）: 仲介手数料（0）・物件購入費（1・自動）・測量費（2）・
 *   その他（3）・旧項目（4・停止中・行なし＝出ない）・停止項目（5・停止中・行があれば出る）。
 */
class CostGridScreenTest extends RealEstateScreenTestCase
{
    private ReCostItem $brokerItem;

    private ReCostItem $otherItem;

    private ReCostItem $stoppedItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brokerItem = ReCostItem::create(['name' => '仲介手数料', 'sort_order' => 0]);
        $this->otherItem = ReCostItem::create(['name' => 'その他', 'sort_order' => 3]);
        ReCostItem::create(['name' => '旧項目', 'sort_order' => 4, 'is_active' => false]);
        $this->stoppedItem = ReCostItem::create(['name' => '停止項目', 'sort_order' => 5, 'is_active' => false]);
    }

    public static function screens(): array
    {
        return ['仕入れ案件' => ['procurement'], '分譲地' => ['project']];
    }

    // ============================================================
    // 助け
    // ============================================================

    /** 価格を持たない案件（物件購入費の自動の行ができない） */
    private function owner(string $kind): Model
    {
        return $kind === 'procurement' ? $this->procurement() : $this->project();
    }

    private function costOf(string $kind, Model $owner, ReCostItem $item, int $est, ?int $act = null, ?string $notes = null): Model
    {
        $attributes = ['cost_item_id' => $item->id, 'estimated_amount' => $est, 'actual_amount' => $act, 'notes' => $notes];

        return $kind === 'procurement'
            ? ReProcurementCost::create($attributes + ['procurement_id' => $owner->id])
            : ReProjectCost::create($attributes + ['project_id' => $owner->id]);
    }

    /** @return list<array{0: string, 1: int, 2: ?int, 3: ?string}> その案件の原価の行（id の順）の [項目名, 見込み額, 確定額, 備考] */
    private function rowsOf(string $kind, Model $owner): array
    {
        $query = $kind === 'procurement'
            ? ReProcurementCost::where('procurement_id', $owner->id)
            : ReProjectCost::where('project_id', $owner->id);

        return $query->with('costItem')->orderBy('id')->get()
            ->map(fn ($c) => [$c->costItem->name, $c->estimated_amount, $c->actual_amount, $c->notes])->all();
    }

    /**
     * 詳細画面を描く（$as で描く人を差し替える）。
     *
     * @return array{0: string, 1: string, 2: Model} [HTML, 画面の関数名, 案件]
     */
    private function page(string $kind, ?User $as = null, ?Model $owner = null): array
    {
        $owner ??= $this->owner($kind);
        $previous = $this->user;
        $this->user = $as ?? $previous;
        try {
            $html = $this->htmlOf(route("realestate.{$kind}s.show", $owner));
        } finally {
            $this->user = $previous;
        }

        return [$html, $kind === 'procurement' ? 'procurementDetail' : 'projectDetail', $owner];
    }

    private function drive(string $html, string $function, string $steps, array $responses = [], array $evaluate = [], bool $confirm = true): array
    {
        return $this->driveAlpine($html, $function, $function . '()', $steps, $responses, $confirm, $evaluate,
            ['function costExcelImporterFactory(', 'function costGridFactory(']);
    }

    /** 取り出した要求を $as として送る */
    private function send(array $request, ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->user);

        return $this->sendCaptured($request);
    }

    /** 表の行（$name の $n 番目）を指す JS の式 */
    private function row(string $name, int $n = 0): string
    {
        return 'data.costGrid.rows.filter(function (r) { return r.name === ' . json_encode($name, JSON_UNESCAPED_UNICODE) . "; })[{$n}]";
    }

    /** 表の行の金額（と備考）を打つ JS */
    private function type(string $name, string $amount, ?string $notes = null, int $n = 0): string
    {
        $js = 'var r = ' . $this->row($name, $n) . '; r.draft.amount = ' . json_encode($amount, JSON_UNESCAPED_UNICODE) . ';';
        if ($notes !== null) {
            $js .= ' r.draft.notes = ' . json_encode($notes, JSON_UNESCAPED_UNICODE) . ';';
        }

        return $js;
    }

    private function summary(int $n): string
    {
        return "保存できませんでした。赤い行を直してください（{$n} 件）。何も保存していません。";
    }

    // ============================================================
    // ボタンと開いたときの行
    // ============================================================

    #[DataProvider('screens')]
    public function test_the_input_buttons_are_shown_to_managers_and_not_to_staff(string $kind): void
    {
        $owner = $this->owner($kind);
        $button = fn (string $mode, string $label) => '/<button\b[^>]*@click="openCostGrid\(\'' . $mode . '\'\)"[^>]*>\s*' . $label . '\s*<\/button>/u';

        foreach ([UserRole::Executive, UserRole::Manager] as $role) {
            [$html] = $this->page($kind, $this->userWithRole($role), $owner);
            $this->assertSame(1, preg_match_all($button('estimated', '見込み額を入力'), $html), "{$role->value} に「見込み額を入力」のボタンが無い");
            $this->assertSame(1, preg_match_all($button('actual', '確定額を入力'), $html), "{$role->value} に「確定額を入力」のボタンが無い");
        }

        [$html] = $this->page($kind, $this->userWithRole(UserRole::Staff), $owner);
        $this->assertStringNotContainsString('@click="openCostGrid(', $html, '一般担当に入力のボタンが出ている');
    }

    /**
     * 詳細画面が表のマークアップ（_cost_grid）を描いていること。ほかのテストは画面の JS（関数）だけを動かすので、
     * 画面から include を外しても（表が出ないのに関数だけ生きている）緑のままだった。
     */
    #[DataProvider('screens')]
    public function test_the_detail_page_renders_the_grid_markup(string $kind): void
    {
        [$html] = $this->page($kind);

        $this->assertSame(1, substr_count($html, ':data-cost-cell="\'estimated-\' + idx"'), '見込み額のセルを描いた表が詳細画面に無い');
        $this->assertSame(1, substr_count($html, ':data-cost-cell="\'actual-\' + idx"'), '確定額のセルを描いた表が詳細画面に無い');
        $this->assertSame(1, substr_count($html, '@click="saveCostGrid()"'), '表の「保存」のボタンが詳細画面に無い');
        $this->assertStringNotContainsString('@click="closeCostGrid()"', $html, '詳細画面に新規登録用の「表を閉じる」が出ている');
    }

    #[DataProvider('screens')]
    public function test_opening_lists_every_item_in_master_order(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 50000);
        $this->costOf($kind, $owner, $this->otherItem, 10000, null, '1 行目');
        $this->costOf($kind, $owner, $this->otherItem, 20000);
        $this->costOf($kind, $owner, $this->stoppedItem, 30000);
        [$html, $function] = $this->page($kind, null, $owner);

        $run = $this->drive($html, $function, 'data.openCostGrid("estimated");', [], [
            'data.costGrid.rows.map(function (r) { return [r.name, r.ref === null ? "空" : "保存"].concat(r.auto ? ["自動"] : []).concat(r.inactive ? ["停止中"] : []).join("/"); })',
            'data.costGrid.rows.map(function (r) { return r.draft.amount; })',
            'data.costGrid.rows.map(function (r) { return r.draft.notes; })',
            'costGrid.mode',
        ]);

        $this->assertSame([
            ['仲介手数料/空', '物件購入費/空/自動', '測量費/保存', 'その他/保存', 'その他/保存', '停止項目/保存/停止中'],
            ['', '', '50,000', '10,000', '20,000', '30,000'],
            ['', '', '', '1 行目', '', ''],
            'estimated',
        ], $run['evaluated']);
    }

    // ============================================================
    // 保存（1 回の要求・変えた行だけ）
    // ============================================================

    #[DataProvider('screens')]
    public function test_the_estimate_grid_is_saved_in_one_request(string $kind): void
    {
        $owner = $this->owner($kind);
        $survey = $this->costOf($kind, $owner, $this->surveyItem, 500000, null, '旧');
        $this->costOf($kind, $owner, $this->otherItem, 10000);
        [$html, $function] = $this->page($kind, null, $owner);
        $steps = 'data.openCostGrid("estimated"); ' . $this->type('仲介手数料', '1,200,000', '現況')
            . ' var s = ' . $this->row('測量費') . '; s.draft.notes = "境界確定"; data.saveCostGrid();';

        $run = $this->drive($html, $function, $steps);

        $this->assertCount(1, $run['requests']);
        $request = $run['requests'][0];
        $this->assertSame('PUT', $request['method']);
        $this->assertSame(route("realestate.{$kind}s.costs.batch", $owner), $request['url']);
        $this->assertSame('XMLHttpRequest', $request['headers']['X-Requested-With'] ?? null);
        $this->assertStringContainsString('application/json', $request['headers']['Content-Type'] ?? '');
        $this->assertStringContainsString('application/json', $request['headers']['Accept'] ?? '');
        $this->assertSame([
            'mode' => 'estimated',
            'rows' => [
                ['id' => null, 'cost_item_id' => $this->brokerItem->id, 'amount' => 1200000, 'notes' => '現況',
                    'original' => ['estimated_amount' => null, 'actual_amount' => null, 'notes' => null]],
                ['id' => $survey->id, 'cost_item_id' => $this->surveyItem->id, 'amount' => 500000, 'notes' => '境界確定',
                    'original' => ['estimated_amount' => 500000, 'actual_amount' => null, 'notes' => '旧']],
            ],
        ], json_decode((string) $request['body'], true), '変えた 2 行だけを、Global Constraints の形で送っていない');

        $response = $this->send($request);
        $response->assertOk();
        $this->assertSame([
            ['測量費', 500000, null, '境界確定'],
            ['その他', 10000, null, null],
            ['仲介手数料', 1200000, null, '現況'],
        ], $this->rowsOf($kind, $owner));

        $after = $this->drive($html, $function, $steps, [$this->asFetchResponse($response)], [
            'costGrid.mode',
            'costs.map(function (c) { return c.cost_item_name + ":" + c.estimated_amount; })',
            'costMessage',
        ]);
        $this->assertSame([
            null,
            ['測量費:500000', 'その他:10000', '仲介手数料:1200000'],
            '見込み額を保存しました（追加 1 件・変更 1 件）。',
        ], $after['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_the_actual_grid_creates_a_zero_estimate_row(string $kind): void
    {
        [$html, $function, $owner] = $this->page($kind);
        $steps = 'data.openCostGrid("actual"); ' . $this->type('測量費', '750000') . ' data.saveCostGrid();';

        $run = $this->drive($html, $function, $steps);
        $this->assertCount(1, $run['requests']);
        $body = json_decode((string) $run['requests'][0]['body'], true);
        $this->assertSame(['actual', 750000, null], [$body['mode'], $body['rows'][0]['amount'], $body['rows'][0]['id']]);

        $response = $this->send($run['requests'][0]);
        $response->assertOk();
        $this->assertSame([['測量費', 0, 750000, null]], $this->rowsOf($kind, $owner));

        $after = $this->drive($html, $function, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage', 'costGrid.mode']);
        $this->assertSame([1, '確定額を保存しました（追加 1 件・変更 0 件）。', null], $after['evaluated']);
    }

    // ============================================================
    // 画面の断り（サーバーと同じ決まり）
    // ============================================================

    public static function clientChecks(): array
    {
        // [表, 行, 保存済みの [見込み額, 確定額], 描く人, 金額, 備考, 断りの MESSAGES のキー（null は送られる）]
        $cases = [
            '数でない'           => ['estimated', '仲介手数料', null, 'executive', 'abc', '', 'notNumber'],
            'マイナス'           => ['estimated', '仲介手数料', null, 'executive', '-5', '', 'notNumber'],
            '上限を超える'       => ['estimated', '仲介手数料', null, 'executive', '2147483648', '', 'tooLarge'],
            '備考だけ（見込み）' => ['estimated', '仲介手数料', null, 'executive', '', 'x', 'notesOnlyEstimated'],
            '備考だけ（確定）'   => ['actual', '仲介手数料', null, 'executive', '', 'x', 'notesOnlyActual'],
            '確定額がある行'     => ['estimated', '測量費', [500000, 480000], 'executive', '', '', 'hasActual'],
            '管理者が消す'       => ['estimated', '測量費', [500000, null], 'manager', '', '', 'onlyExecutive'],
            '上限ちょうど'       => ['estimated', '仲介手数料', null, 'executive', '2,147,483,647', '', null],
        ];
        $out = [];
        foreach (self::screens() as $screen => [$kind]) {
            foreach ($cases as $name => $case) {
                $out["{$screen} / {$name}"] = array_merge([$kind], $case);
            }
        }

        return $out;
    }

    #[DataProvider('clientChecks')]
    public function test_client_checks_match_the_server_rules(string $kind, string $mode, string $name, ?array $saved, string $role, string $amount, string $notes, ?string $key): void
    {
        $owner = $this->owner($kind);
        $item = $name === '測量費' ? $this->surveyItem : $this->brokerItem;
        if ($saved !== null) {
            $this->costOf($kind, $owner, $item, $saved[0], $saved[1]);
        }
        $as = $role === 'manager' ? $this->userWithRole(UserRole::Manager) : $this->user;
        [$html, $function] = $this->page($kind, $as, $owner);
        $steps = 'data.openCostGrid(' . json_encode($mode) . '); ' . $this->type($name, $amount, $notes) . ' data.saveCostGrid();';

        $run = $this->drive($html, $function, $steps, [], [
            'data.costGrid.rowErrors[' . $this->row($name) . '.key]',
            'costGrid.error',
            'costGrid.mode',
        ]);

        if ($key === null) {
            $this->assertCount(1, $run['requests'], '上限ちょうどの金額が送られていない');
            $this->assertSame(2147483647, json_decode((string) $run['requests'][0]['body'], true)['rows'][0]['amount']);
            $this->send($run['requests'][0], $as)->assertOk();

            return;
        }
        $this->assertSame([], $run['requests'], '画面で断るはずの行が送られた');
        $this->assertSame([RealEstateCostGrid::MESSAGES[$key], $this->summary(1), $mode], $run['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_deleting_rows_asks_first_and_names_them(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 500000);
        $this->costOf($kind, $owner, $this->otherItem, 10000, null, '1 行目');
        $this->costOf($kind, $owner, $this->otherItem, 20000, null, '2 行目');
        [$html, $function] = $this->page($kind, null, $owner);
        $steps = 'data.openCostGrid("estimated"); ' . $this->type('測量費', '') . ' ' . $this->type('その他', '') . ' data.saveCostGrid();';

        $declined = $this->drive($html, $function, $steps, [], ['costGrid.mode'], false);
        $this->assertSame(['次の 2 件の行を削除します: 測量費、その他。よろしいですか？'], $declined['confirms']);
        $this->assertSame([], $declined['requests'], '「キャンセル」なのに送った');
        $this->assertSame(['estimated'], $declined['evaluated'], '「キャンセル」で表が閉じた');

        $run = $this->drive($html, $function, $steps);
        $this->assertCount(1, $run['requests']);
        $response = $this->send($run['requests'][0]);
        $response->assertOk();
        $this->assertSame([['その他', 20000, null, '2 行目']], $this->rowsOf($kind, $owner));

        $after = $this->drive($html, $function, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage']);
        $this->assertSame([1, '見込み額を保存しました（追加 0 件・変更 0 件・削除 2 件）。'], $after['evaluated']);
    }

    // ============================================================
    // サーバーの断りを画面に戻す
    // ============================================================

    #[DataProvider('screens')]
    public function test_server_row_errors_are_shown_on_the_rows(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 500000);
        [$html, $function] = $this->page($kind, null, $owner);
        $steps = 'data.openCostGrid("estimated"); ' . $this->type('測量費', '') . ' data.saveCostGrid();';

        $run = $this->drive($html, $function, $steps);
        $this->assertCount(1, $run['requests']);
        // 経営層の画面が組んだ要求を、管理者として送る（画面を開いたあとで役割が変わった・手で送った）
        $response = $this->send($run['requests'][0], $this->userWithRole(UserRole::Manager));
        $response->assertStatus(422)->assertJsonValidationErrors(['rows.0.amount' => RealEstateCostGrid::MESSAGES['onlyExecutive']]);

        $after = $this->drive($html, $function, $steps, [$this->asFetchResponse($response)], [
            'data.costGrid.rowErrors[' . $this->row('測量費') . '.key]',
            'Object.keys(costGrid.rowErrors).length',
            'costGrid.error',
            'costGrid.mode',
            'costGrid.saving',
        ]);
        $this->assertSame([RealEstateCostGrid::MESSAGES['onlyExecutive'], 1, $this->summary(1), 'estimated', false], $after['evaluated']);
        $this->assertSame([['測量費', 500000, null, null]], $this->rowsOf($kind, $owner));
    }

    #[DataProvider('screens')]
    public function test_a_change_by_someone_else_is_shown_and_nothing_is_saved(string $kind): void
    {
        $owner = $this->owner($kind);
        $survey = $this->costOf($kind, $owner, $this->surveyItem, 500000);
        [$html, $function] = $this->page($kind, null, $owner);
        $steps = 'data.openCostGrid("estimated"); ' . $this->type('測量費', '600000') . ' data.saveCostGrid();';

        $run = $this->drive($html, $function, $steps);
        $this->assertCount(1, $run['requests']);
        // 表を開いたあとで、ほかの人が同じ行を変えた
        $survey->update(['estimated_amount' => 700000]);
        $response = $this->send($run['requests'][0]);
        $response->assertStatus(422)->assertJsonValidationErrors(['rows' => RealEstateCostGrid::MESSAGES['conflict']]);

        $after = $this->drive($html, $function, $steps, [$this->asFetchResponse($response)], [
            'costGrid.error', 'Object.keys(costGrid.rowErrors).length', 'costGrid.mode',
        ]);
        $this->assertSame([RealEstateCostGrid::MESSAGES['conflict'], 0, 'estimated'], $after['evaluated']);
        $this->assertSame([['測量費', 700000, null, null]], $this->rowsOf($kind, $owner), 'ほかの人の値を上書きした');
    }

    #[DataProvider('screens')]
    public function test_two_rows_of_the_same_item_are_edited_separately(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->otherItem, 10000, null, '1 行目');
        $second = $this->costOf($kind, $owner, $this->otherItem, 20000, null, '2 行目');
        [$html, $function] = $this->page($kind, null, $owner);

        $run = $this->drive($html, $function, 'data.openCostGrid("estimated"); ' . $this->type('その他', '25000', null, 1) . ' data.saveCostGrid();');

        $this->assertCount(1, $run['requests']);
        $rows = json_decode((string) $run['requests'][0]['body'], true)['rows'];
        $this->assertSame([[$second->id, 25000]], array_map(fn ($r) => [$r['id'], $r['amount']], $rows));
        $this->send($run['requests'][0])->assertOk();
        $this->assertSame([['その他', 10000, null, '1 行目'], ['その他', 25000, null, '2 行目']], $this->rowsOf($kind, $owner));
    }

    // ============================================================
    // 貼り付け・キーの移動
    // ============================================================

    #[DataProvider('screens')]
    public function test_paste_fills_down_skipping_auto_rows_and_blank_cells(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 50000);
        [$html, $function] = $this->page($kind, null, $owner);
        $paste = fn (string $text) => 'data.openCostGrid("estimated"); '
            . 'var e = { clipboardData: { getData: function () { return ' . json_encode($text, JSON_UNESCAPED_UNICODE) . '; } }, prevented: false, preventDefault: function () { this.prevented = true; } }; '
            . 'globalThis.__pasteEvent = e; data.costGridPaste(e, 0);';
        $evaluate = [
            'globalThis.__pasteEvent.prevented',
            'data.costGrid.rows.map(function (r) { return r.name + ":" + r.draft.amount; })',
            'costGrid.notice',
        ];

        $run = $this->drive($html, $function, $paste("１，２００円\n\n300\t9\n400\n"), [], $evaluate);
        $this->assertSame([
            true,
            ['仲介手数料:1,200', '物件購入費:', '測量費:50,000', 'その他:300'],
            '2 行に貼り付けました（空のセル 1 個は今の値のまま）。まだ保存していません。',
        ], $run['evaluated']);

        // 1 つの値（改行なし）は、ふつうの貼り付けに任せる
        $run = $this->drive($html, $function, $paste('5000'), [], $evaluate);
        $this->assertSame([false, ['仲介手数料:', '物件購入費:', '測量費:50,000', 'その他:'], ''], $run['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_enter_and_arrows_move_between_editable_cells(string $kind): void
    {
        [$html, $function] = $this->page($kind);
        $steps = 'data.openCostGrid("estimated"); globalThis.__key = function (extra, idx) { '
            . 'var e = Object.assign({ key: "", shiftKey: false, isComposing: false, keyCode: 0, prevented: false, preventDefault: function () { this.prevented = true; } }, extra); '
            . 'var to = data.costGridKey(e, idx); return [to, e.prevented]; };';

        // 行: 0 仲介手数料・1 物件購入費（自動）・2 測量費・3 その他
        $run = $this->drive($html, $function, $steps, [], [
            '__key({ key: "Enter" }, 0)',
            '__key({ key: "Enter", shiftKey: true }, 2)',
            '__key({ key: "ArrowDown" }, 0)',
            '__key({ key: "ArrowUp" }, 2)',
            '__key({ key: "Enter" }, 3)',
            '__key({ key: "Enter", isComposing: true }, 0)',
            '__key({ key: "Enter", keyCode: 229 }, 0)',
            '__key({ key: "a" }, 0)',
        ]);

        $this->assertSame([
            [2, true],
            [0, true],
            [2, true],
            [0, true],
            [null, true],
            [null, false],
            [null, false],
            [null, false],
        ], $run['evaluated']);
    }

    /** 備考のセルも Enter で下・Shift + Enter で上・矢印で動き、新規登録のフォームの送信にしない（物件購入費の行は飛ばす） */
    #[DataProvider('screens')]
    public function test_enter_and_arrows_move_between_notes_cells(string $kind): void
    {
        [$html, $function] = $this->page($kind);
        $steps = 'data.openCostGrid("estimated"); globalThis.__key = function (extra, idx) { '
            . 'var e = Object.assign({ key: "", shiftKey: false, isComposing: false, keyCode: 0, prevented: false, preventDefault: function () { this.prevented = true; } }, extra); '
            . 'var to = data.costGridKey(e, idx, "notes"); return [to, e.prevented]; };';

        $run = $this->drive($html, $function, $steps, [], [
            '__key({ key: "Enter" }, 0)',
            '__key({ key: "Enter", shiftKey: true }, 2)',
            '__key({ key: "ArrowDown" }, 0)',
            '__key({ key: "ArrowUp" }, 2)',
            '__key({ key: "Enter" }, 3)',
            '__key({ key: "Enter", isComposing: true }, 0)',
            '__key({ key: "Enter", keyCode: 229 }, 0)',
            '__key({ key: "a" }, 0)',
        ]);

        $this->assertSame([[2, true], [0, true], [2, true], [0, true], [null, true], [null, false], [null, false], [null, false]], $run['evaluated']);

        // 移る先は備考のセル（金額のセルではない）
        $run = $this->drive($html, $function, 'data.openCostGrid("estimated"); globalThis.__q = []; document.querySelector = function (s) { globalThis.__q.push(s); return null; }; '
            . 'data.costGridKey({ key: "Enter", shiftKey: false, preventDefault: function () {} }, 0, "notes");', [], ['globalThis.__q']);
        $this->assertSame([['[data-cost-note="2"]']], $run['evaluated']);
    }

    // ============================================================
    // 変更の数・取消・ページを離れるとき
    // ============================================================

    #[DataProvider('screens')]
    public function test_change_count_and_cancel_confirmation(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 50000, null, 'a');
        [$html, $function] = $this->page($kind, null, $owner);
        $changed = 'data.openCostGrid("estimated"); ' . $this->type('仲介手数料', '1000')
            . ' var s = ' . $this->row('測量費') . '; s.draft.notes = "b"; globalThis.__count = data.costGridChangeCount(); data.cancelCostGrid();';

        $declined = $this->drive($html, $function, $changed, [], ['globalThis.__count', 'costGrid.mode'], false);
        $this->assertSame(['保存していない変更が 2 件あります。捨ててよいですか？'], $declined['confirms']);
        $this->assertSame([2, 'estimated'], $declined['evaluated'], '「キャンセル」で表が閉じた');

        $accepted = $this->drive($html, $function, $changed, [], ['costGrid.mode']);
        $this->assertSame([null], $accepted['evaluated']);

        $untouched = $this->drive($html, $function, 'data.openCostGrid("estimated"); ' . $this->type('測量費', '50,000') . ' data.cancelCostGrid();', [], ['costGrid.mode']);
        $this->assertSame([], $untouched['confirms'], '変えていないのに確かめた');
        $this->assertSame([null], $untouched['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_leaving_with_unsaved_changes_asks(string $kind): void
    {
        [$html, $function] = $this->page($kind);
        $fire = '__fireWindowEvent("beforeunload")';

        $changed = $this->drive($html, $function, 'data.openCostGrid("estimated"); ' . $this->type('測量費', '1000'), [], [$fire]);
        $this->assertSame([['prevented' => true, 'returnValue' => '']], $changed['evaluated']);

        $untouched = $this->drive($html, $function, 'data.openCostGrid("estimated");', [], [$fire]);
        $this->assertSame([['prevented' => false, 'returnValue' => null]], $untouched['evaluated']);

        $closed = $this->drive($html, $function, 'data.openCostGrid("estimated"); ' . $this->type('測量費', '1000') . ' data.cancelCostGrid();', [], [$fire, 'costGrid.mode']);
        $this->assertSame([['prevented' => false, 'returnValue' => null], null], $closed['evaluated']);
    }

    // ============================================================
    // 数の読み方・整え方・合計
    // ============================================================

    #[DataProvider('screens')]
    public function test_parsing_formatting_and_totals(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->purchaseItem, 3000000, 2900000);
        $this->costOf($kind, $owner, $this->surveyItem, 50000);
        $this->costOf($kind, $owner, $this->otherItem, 10000, 12000);
        [$html, $function] = $this->page($kind, null, $owner);
        $steps = 'data.openCostGrid("actual"); '
            . $this->type('測量費', '１２０,０００円 ') . ' data.costGridTidy(r); '
            . $this->type('その他', '8000') . ' '
            . $this->type('仲介手数料', '1000');

        $run = $this->drive($html, $function, $steps, [], [
            $this->row('測量費') . '.draft.amount',
            'data.costGridDiffText(' . $this->row('測量費') . ')',
            'data.costGridDiffStyle(' . $this->row('測量費') . ').indexOf("#B91C1C") !== -1',
            'data.costGridDiffText(' . $this->row('その他') . ')',
            'data.costGridDiffStyle(' . $this->row('その他') . ').indexOf("#047857") !== -1',
            // 確定額だけ入れた新しい行は、見込み額 0 の行になる
            'data.costGridValue(' . $this->row('仲介手数料') . ', "est")',
            '[costGridTotal("est"), costGridTotal("act"), costGridTotal("eff"), costGridTotalDiff()]',
            '[costGridParse(""), isNaN(costGridParse("abc")), isNaN(costGridParse("-5")), costGridParse("１，２００円"), costGridFormat(1234567), costGridFormat(0)]',
        ]);

        $this->assertSame([
            '120,000',
            '+70,000',
            true,
            '−2,000',
            true,
            0,
            // 見込み: 物件購入費 3,000,000 + 測量費 50,000 + その他 10,000 + 仲介手数料 0
            // 確定: 2,900,000 + 120,000 + 8,000 + 1,000 ／ 採用額は全部の行で確定額 ／ 差額は確定額のある行の（確定 − 見込み）
            [3060000, 3029000, 3029000, -31000],
            ['', true, true, 1200, '1,234,567', '0'],
        ], $run['evaluated']);
    }

    #[DataProvider('screens')]
    public function test_saving_without_changes_sends_nothing(string $kind): void
    {
        $owner = $this->owner($kind);
        $this->costOf($kind, $owner, $this->surveyItem, 50000);
        [$html, $function] = $this->page($kind, null, $owner);

        $run = $this->drive($html, $function, 'data.openCostGrid("estimated"); data.saveCostGrid();', [], ['costGrid.mode', 'costMessage']);

        $this->assertSame([], $run['requests']);
        $this->assertSame([null, '変更はありませんでした。'], $run['evaluated']);
    }

    // ============================================================
    // マークアップの決まり（このプロジェクトの罠）
    // ============================================================

    public function test_the_grid_markup_follows_the_traps(): void
    {
        $src = file_get_contents(resource_path('views/realestate/_partials/_cost_grid.blade.php'));
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        preg_match_all('/<([a-zA-Z][\w-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $src, $tags, PREG_SET_ORDER);

        $amountCells = array_values(array_filter($tags, fn ($t) => $t[1] === 'input' && str_contains($t[2], 'data-cost-cell')));
        $this->assertCount(2, $amountCells, '金額のセル（見込み額・確定額）がちょうど 2 つ無い');
        foreach ($amountCells as [$tag]) {
            foreach ([
                'inputmode="numeric"',
                '@keydown="$event.isComposing || costGridKey($event, idx)"',
                '@paste="costGridPaste($event, idx)"',
                '@focus="$event.target.select()"',
                '@blur="costGridTidy(row)"',
            ] as $needle) {
                $this->assertStringContainsString($needle, $tag, "金額のセルに {$needle} が無い");
            }
        }

        $inputs = array_values(array_filter($tags, fn ($t) => $t[1] === 'input'));
        $notes = array_values(array_filter($inputs, fn ($t) => str_contains($t[2], 'draft.notes')));
        $this->assertCount(1, $notes, '備考のセルがちょうど 1 つ無い');
        $this->assertStringContainsString('maxlength="200"', $notes[0][0]);
        // 新規登録のフォームの中で Enter が登録の送信にならない・日本語入力の確定の Enter では動かない
        $this->assertStringContainsString('@keydown="$event.isComposing || costGridKey($event, idx, \'notes\')"', $notes[0][0]);
        $this->assertStringContainsString(':data-cost-note="idx"', $notes[0][0]);
        foreach ($inputs as [$tag]) {
            // 新規登録のフォームの中に置くので、name を付けると「登録する」で送られてしまう
            $this->assertDoesNotMatchRegularExpression('/(?<![\w:-])name=/', $tag, "入力に name がある: {$tag}");
        }

        foreach ($tags as [$tag]) {
            $this->assertFalse(
                (bool) preg_match('/(?<![\w:-])style="/', $tag) && str_contains($tag, ':style="'),
                "同じ要素に style= と :style= が並んでいる（Top trap #5）: {$tag}"
            );
        }

        $table = strpos($src, '<table');
        $this->assertNotFalse($table);
        $this->assertMatchesRegularExpression('/<table\b[^>]*min-width:\s*\d+px/', $src, '表に min-width が無い（375px で列が潰れる）');
        $scroller = strrpos(substr($src, 0, $table), 'overflow-x-auto');
        $this->assertNotFalse($scroller, '表が横スクロールの枠（overflow-x-auto）の中に無い');

        $this->assertMatchesRegularExpression('/<button\b[^>]*@click="saveCostGrid\(\)"[^>]*:disabled="costGrid\.saving"/', $src, '保存のボタンが送信中に押せてしまう');
    }
}
