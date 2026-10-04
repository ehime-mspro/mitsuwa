<?php

namespace Tests\Feature\Admin\Master;

use Illuminate\Support\Facades\DB;

/**
 * 一覧の中で追加・名前の変更・削除・ドラッグの並び替えをするマスタ（用途・構造・用途地域・原価項目）の共通のテスト。
 * 4 つの画面は同じ作り（`admin/master/<prefix>/index.blade.php`。Alpine の `…Manager()` が隠しフォーム
 * addForm / editForm / deleteForm の送り先と値を入れて submit() し、並び替えは fetch で JSON を送る）。
 *
 * 子クラスはマスタごとの違い（表・名前の上限・使用中の作り方と文言）だけを持つ。
 * ⚠ 名前は 3 つ入れ、並び替えは「先頭の行を 3 番目へ落とす」で測る（2 つだと前後を入れ替えるだけになり、
 *   splice の向きを間違えても同じ結果になる）。
 */
abstract class InlineNameMasterTestCase extends MasterScreenTestCase
{
    /** ルートと URL の名前（例 usage-types） */
    abstract protected function prefix(): string;

    /** 画面の x-data の関数名（例 usageTypeManager） */
    abstract protected function jsFunction(): string;

    abstract protected function table(): string;

    /** 並び順どおりの名前 3 つ */
    abstract protected function names(): array;

    /** 名前の上限（入力チェック。列の大きさと同じ） */
    abstract protected function maxLength(): int;

    /** $id の項目を、削除を断られる使われ方にする */
    abstract protected function markInUse(int $id, string $name): void;

    abstract protected function inUseMessage(string $name): string;

    /** @var array<string, int> 名前 => id */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMasterSchema();
        foreach ($this->names() as $i => $name) {
            $this->ids[$name] = DB::table($this->table())->insertGetId([
                'name' => $name, 'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** マスタの表（と使用中の確かめに要る表）を作る */
    abstract protected function createMasterSchema(): void;

    protected function indexUrl(): string
    {
        return route('admin.master.' . $this->prefix() . '.index');
    }

    /** 並び順どおりの名前 */
    protected function namesInOrder(): array
    {
        return DB::table($this->table())->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
    }

    protected function screenState(): array
    {
        $html = $this->htmlOf($this->indexUrl());

        return $this->driveAlpine($html, $this->jsFunction(), $this->xData($html, $this->jsFunction()), '')['state'];
    }

    public function test_the_list_hands_the_items_to_the_screen_in_order(): void
    {
        $state = $this->screenState();

        $this->assertSame($this->names(), array_column($state['items'], 'name'));
        $this->assertSame(array_values($this->ids), array_column($state['items'], 'id'));
    }

    public function test_an_item_is_added_from_the_screen(): void
    {
        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "data.startAdd(); data.newName = '追加した名前'; data.submitAdd();");

        $this->assertSame('addForm', $sent['form']['ref']);
        $this->assertSame('POST', $sent['form']['method']);
        $this->assertSame(route('admin.master.' . $this->prefix() . '.store'), $sent['form']['action']);
        $sent['response']->assertRedirect($this->indexUrl());
        $this->assertSame(array_merge($this->names(), ['追加した名前']), $this->namesInOrder(), '追加した項目が末尾に並ばない');
        $this->assertSame(4, (int) DB::table($this->table())->where('name', '追加した名前')->value('sort_order'));
        $this->assertFlash($this->landed($sent['response']), 'success', '「追加した名前」を追加しました。');
    }

    public function test_a_name_up_to_the_column_size_is_added_and_a_longer_one_is_refused(): void
    {
        $max = $this->maxLength();

        $ok = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "data.startAdd(); data.newName = '" . str_repeat('あ', $max) . "'; data.submitAdd();");
        $ok['response']->assertSessionHasNoErrors();
        $this->assertTrue(DB::table($this->table())->where('name', str_repeat('あ', $max))->exists());

        $long = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "data.startAdd(); data.newName = '" . str_repeat('い', $max + 1) . "'; data.submitAdd();");
        $long['response']->assertRedirect($this->indexUrl());
        $this->assertFalse(DB::table($this->table())->where('name', str_repeat('い', $max + 1))->exists());
        $this->assertStringContainsString(
            '<p class="text-sm text-red-800">' . trans('validation.max.string', ['attribute' => trans('validation.attributes.name'), 'max' => $max]) . '</p>',
            $this->landed($long['response'])
        );
    }

    public function test_a_blank_name_is_not_sent(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $factory = $this->xData($html, $this->jsFunction());

        $add = $this->driveAlpine($html, $this->jsFunction(), $factory, "data.startAdd(); data.newName = '   '; data.submitAdd();");
        $edit = $this->driveAlpine($html, $this->jsFunction(), $factory,
            "data.startEdit(data.items[0].id, data.items[0].name); data.editingName = ''; data.submitEdit();");

        $this->assertSame([], $add['submitted'], '名前が空でも追加を送った');
        $this->assertSame([], $edit['submitted'], '名前が空でも変更を送った');
    }

    public function test_an_item_is_renamed_from_the_screen(): void
    {
        [, $second] = $this->names();

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "var it = data.items[1]; data.startEdit(it.id, it.name); data.editingName = '変更後の名前'; data.submitEdit();");

        $this->assertSame('editForm', $sent['form']['ref']);
        $this->assertSame('PUT', $sent['form']['fields']['_method'] ?? null);
        $this->assertSame(route('admin.master.' . $this->prefix() . '.update', $this->ids[$second]), $sent['form']['action']);
        $sent['response']->assertRedirect($this->indexUrl());
        $this->assertSame('変更後の名前', DB::table($this->table())->where('id', $this->ids[$second])->value('name'));
        $this->assertFlash($this->landed($sent['response']), 'success', '「変更後の名前」を更新しました。');
    }

    public function test_an_unused_item_is_deleted_from_the_screen(): void
    {
        [, , $third] = $this->names();

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "var it = data.items[2]; data.startDelete(it.id, it.name); data.submitDelete();");

        $this->assertSame('deleteForm', $sent['form']['ref']);
        $this->assertSame('DELETE', $sent['form']['fields']['_method'] ?? null);
        $this->assertSame(route('admin.master.' . $this->prefix() . '.destroy', $this->ids[$third]), $sent['form']['action']);
        $sent['response']->assertRedirect($this->indexUrl());
        $this->assertFalse(DB::table($this->table())->where('id', $this->ids[$third])->exists());
        $this->assertFlash($this->landed($sent['response']), 'success', "「{$third}」を削除しました。");
    }

    public function test_an_item_in_use_is_not_deleted_and_the_reason_is_shown(): void
    {
        [$first] = $this->names();
        $this->markInUse($this->ids[$first], $first);

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            "var it = data.items[0]; data.startDelete(it.id, it.name); data.submitDelete();");

        $sent['response']->assertRedirect($this->indexUrl());
        $this->assertTrue(DB::table($this->table())->where('id', $this->ids[$first])->exists(), '使用中の項目が消えた');
        $this->assertFlash($this->landed($sent['response']), 'error', $this->inUseMessage($first));
    }

    /** 先頭の行を 3 番目へドラッグすると、JS が組んだ並び替えが保存され、トーストが出る */
    public function test_dragging_a_row_saves_the_new_order(): void
    {
        [$first, $second, $third] = $this->names();
        $html = $this->htmlOf($this->indexUrl());
        $factory = $this->xData($html, $this->jsFunction());
        $ev = $this->dragEventJs();
        $steps = "data.handleDragStart(0, {$ev}); data.handleDragOver(2, {$ev}); data.handleDrop(2, {$ev});";

        $sent = $this->driveAlpine($html, $this->jsFunction(), $factory, $steps);
        $this->assertCount(1, $sent['requests'], 'ドロップしても並び替えを送らなかった');
        $request = $sent['requests'][0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(route('admin.master.' . $this->prefix() . '.reorder'), $request['url']);
        $this->assertSame('application/json', $request['headers']['Accept'] ?? null);
        $this->assertSame(['ids' => [$this->ids[$second], $this->ids[$third], $this->ids[$first]]], json_decode((string) $request['body'], true));

        $response = $this->actingAs($this->user)->sendCaptured($request)->assertOk();
        $after = $this->driveAlpine($html, $this->jsFunction(), $factory, $steps, [$this->asFetchResponse($response)]);

        $this->assertSame([$second, $third, $first], $this->namesInOrder());
        $this->assertSame([1, 2, 3], DB::table($this->table())->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('並び順を更新しました', $after['state']['reorderMessage']);
        $this->assertSame([], $after['alerts']);
        $this->assertSame([$second, $third, $first], array_column($this->screenState()['items'], 'name'), '開き直した一覧が新しい順でない');
    }

    /** 画面を開いたあとで項目が消えていたら、並び替えは断られ、理由が画面に出る（並びは変わらない） */
    public function test_an_order_with_a_vanished_item_shows_the_reason(): void
    {
        [, , $third] = $this->names();
        $html = $this->htmlOf($this->indexUrl());
        DB::table($this->table())->where('id', $this->ids[$third])->delete();
        $factory = $this->xData($html, $this->jsFunction());
        $ev = $this->dragEventJs();
        $steps = "data.handleDragStart(0, {$ev}); data.handleDrop(1, {$ev});";

        $request = $this->driveAlpine($html, $this->jsFunction(), $factory, $steps)['requests'][0];
        $response = $this->actingAs($this->user)->sendCaptured($request)->assertStatus(422);
        $after = $this->driveAlpine($html, $this->jsFunction(), $factory, $steps, [$this->asFetchResponse($response)]);

        $this->assertCount(1, $after['alerts']);
        $this->assertStringContainsString(trans('validation.exists', ['attribute' => '並び順']), $after['alerts'][0]);
        $this->assertSame('', $after['state']['reorderMessage']);
        $this->assertSame(array_slice($this->names(), 0, 2), $this->namesInOrder(), '断られたのに並びが変わった');
    }
}
