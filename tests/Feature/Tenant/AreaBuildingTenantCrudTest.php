<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;

class AreaBuildingTenantCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_a_tenant(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'floor'        => 3,
            'room_number'  => '301',
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'status'       => AreaTenantStatus::Operating->value,
            'confirmed_on' => '2026-08-10',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $tenant = AreaBuildingTenant::firstOrFail();
        $this->assertSame(3, $tenant->floor);
        $this->assertSame(AreaTenantStatus::Operating, $tenant->status);
        $this->assertSame($building->id, $tenant->area_building_id);
        $this->assertSame('2026-08-10', $tenant->confirmed_on->format('Y-m-d'));
    }

    /** 地下は負数（B1 = -1） */
    public function test_basement_floor_is_stored_as_a_negative_number(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'floor'  => -1,
            'status' => AreaTenantStatus::Vacant->value,
        ])->assertRedirect();

        $this->assertSame('B1F', AreaBuildingTenant::firstOrFail()->floorLabel());
    }

    /**
     * 「保存して続けて登録」（設計 §5.6）。1 棟 10〜20 区画なので往復を減らす。
     * ⚠ keep_adding は validate() に載せない（項目名が要らないうえ、画面の入力ではないため）。
     */
    public function test_keep_adding_returns_to_the_create_screen(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'name'        => '1件目',
            'status'      => AreaTenantStatus::Operating->value,
            'keep_adding' => '1',
        ])->assertRedirect(route('tenant.area-buildings.tenants.create', $building));

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/tenants", [
            'name'   => '2件目',
            'status' => AreaTenantStatus::Operating->value,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(2, $building->tenants()->count());
    }

    /** 退去日を入れると現況リストから外れて履歴になる（行は消えない） */
    public function test_setting_moved_out_on_moves_the_row_to_history(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => '撤退カフェ']);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
                'name'         => '撤退カフェ',
                'status'       => AreaTenantStatus::Operating->value,
                'moved_out_on' => '2026-07-31',
            ])
            ->assertRedirect();

        $this->assertSame(0, $building->activeTenants()->count());
        $this->assertSame(1, $building->tenants()->count());
    }

    /**
     * 項目名は画面ラベルどおり（第3引数の上書きが効いていること。Bug #37）。
     *
     * グローバルの既定は name→名称 / room_number→号室 / floor→階数 / status→ステータス なので、
     * 上書きを外すとここが赤になる。⚠ グローバル側を書き換えて緑にしてはいけない
     * （同じキーを別の語で使う画面が壊れる。JapaneseValidationMessagesTest が併せて固定している）。
     *
     * ⚠ **store と update の 2 入口でループさせる。** 片方しか叩かないと、もう片方の
     *   第3引数を削除しても全部緑になる（Bug #44。Task 9 で実際に踏んだ）。
     * ⚠ **`session('errors')` を触る前に assertSessionHasErrors を通すこと。**
     *   通す前は生の配列で返ってきて `->first()` が Error になる（実測）。
     */
    public function test_error_labels_match_the_screen(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);
        $manager  = $this->manager();

        $payload = [
            'status'      => 'closed',
            'name'        => str_repeat('あ', 256),
            'room_number' => str_repeat('A', 51),
            'floor'       => 999,
        ];

        $entries = [
            'store' => fn () => $this->actingAs($manager)
                ->from(route('tenant.area-buildings.tenants.create', $building))
                ->post("/tenant/area-buildings/{$building->id}/tenants", $payload),
            'update' => fn () => $this->actingAs($manager)
                ->from(route('tenant.area-buildings.tenants.edit', [$building, $tenant]))
                ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", $payload),
        ];

        // キー => [画面のラベル, 上書きを外したときに出るグローバルの語]
        $expected = [
            'name'        => ['テナント名', '名称'],
            'room_number' => ['部屋番号', '号室'],
            'floor'       => ['階', '階数'],
            'status'      => ['状態', 'ステータス'],
        ];

        foreach ($entries as $entry => $send) {
            $send()->assertSessionHasErrors(array_keys($expected));
            $errors = session('errors');

            foreach ($expected as $key => [$screen, $global]) {
                $this->assertStringContainsString($screen, $errors->first($key), "{$entry}() / {$key} の項目名が画面ラベルと違う");
                $this->assertStringNotContainsString($global, $errors->first($key), "{$entry}() / {$key} がグローバルの語のまま（第3引数の上書きが効いていない）");
            }
        }
    }

    public function test_tenant_of_another_building_is_404(): void
    {
        $mine   = $this->makeBuilding('自分のビル');
        $others = $this->makeBuilding('別のビル');
        $tenant = $this->makeTenant($others, ['name' => '他所のテナント']);

        $manager = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}/edit")->assertNotFound();
        $this->actingAs($manager)->put("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}", ['status' => 'vacant'])->assertNotFound();
        $this->actingAs($this->executive())->delete("/tenant/area-buildings/{$mine->id}/tenants/{$tenant->id}")->assertNotFound();

        $this->assertSame(1, $others->tenants()->count());
        $this->assertSame('他所のテナント', $tenant->fresh()->name);
    }

    public function test_permissions(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);
        $staff    = $this->staff();

        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/tenants/create")->assertForbidden();
        $this->actingAs($staff)->post("/tenant/area-buildings/{$building->id}/tenants", ['status' => 'vacant'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->assertForbidden();
        $this->actingAs($staff)->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", ['status' => 'vacant'])->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")->assertForbidden();

        $this->actingAs($this->manager())->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")->assertForbidden();

        $this->assertSame(1, $building->tenants()->count(), '拒否されたはずの操作で行が消えている');
        $this->assertSame('A', $tenant->fresh()->name, '拒否されたはずの操作で行が書き換わっている');
    }

    public function test_executive_can_delete(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}")
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_tenants', ['id' => $tenant->id]);
    }

    /** ⚠ 状態セレクトの option は @foreach で静的に生成する（Bug #16） */
    public function test_status_options_are_static(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/tenants/create")
            ->getContent();

        // ⚠ ページ全体で 'x-for' を探してはいけない。実測:
        //   str_contains('x-form-actions', 'x-for') === true。セレクトの中だけを見る。
        $select = $this->extractSelect($html, 'status');
        foreach (AreaTenantStatus::cases() as $case) {
            $this->assertStringContainsString('value="' . $case->value . '"', $select, $case->name);
            $this->assertStringContainsString($case->label(), $select, $case->name);
        }
        $this->assertStringNotContainsString('x-for', $select);
    }

    /**
     * 画面が出す選択肢を、受け側のルールが全部受け付けること。
     *
     * ⚠ ルールに `in:operating,vacant,unknown` と値を**手で並べる**と、Enum に case を
     *   足したとき「セレクトには出るのに保存できない」が無音で起きる（Bug #41「同じ知識の
     *   経路が複数あり片方だけ直す」の型）。この 1 本があると、その形に戻した瞬間に赤くなる。
     */
    public function test_every_status_case_is_accepted_on_both_entry_points(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => 'A']);
        $manager  = $this->manager();

        foreach (AreaTenantStatus::cases() as $case) {
            $this->actingAs($manager)->post("/tenant/area-buildings/{$building->id}/tenants", [
                'name'   => '追加 ' . $case->value,
                'status' => $case->value,
            ])->assertSessionHasNoErrors();

            $this->actingAs($manager)
                ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
                    'name'   => 'A',
                    'status' => $case->value,
                ])->assertSessionHasNoErrors();

            $this->assertSame($case, $tenant->fresh()->status, "{$case->value} が保存できない");
        }
    }

    /**
     * 経営層でも追加・編集できること。
     * ⚠ これが無いと `role:executive,manager` から executive を落としても全部緑のまま通る。
     */
    public function test_executive_can_add_and_edit(): void
    {
        $building  = $this->makeBuilding('ミツワビル');
        $executive = $this->executive();

        $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}/tenants/create")->assertOk();
        $this->actingAs($executive)->post("/tenant/area-buildings/{$building->id}/tenants", [
            'name'   => '経営層が入れた行',
            'status' => AreaTenantStatus::Operating->value,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $tenant = AreaBuildingTenant::firstOrFail();

        $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->assertOk();
        $this->actingAs($executive)->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
            'name'   => '書き換えた',
            'status' => AreaTenantStatus::Vacant->value,
        ])->assertRedirect();

        $this->assertSame('書き換えた', $tenant->fresh()->name);
    }

    // ============================================================
    // 詳細画面の導線
    // ============================================================

    public function test_detail_shows_tenant_links_according_to_role(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => '大街道珈琲']);

        $create = 'href="' . route('tenant.area-buildings.tenants.create', $building) . '"';
        $edit   = 'href="' . route('tenant.area-buildings.tenants.edit', [$building, $tenant]) . '"';
        $delete = 'action="' . route('tenant.area-buildings.tenants.destroy', [$building, $tenant]) . '"';

        $staff = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");
        $staff->assertDontSee($create, false);
        $staff->assertDontSee($edit, false);
        $staff->assertDontSee($delete, false);

        $manager = $this->actingAs($this->manager())->get("/tenant/area-buildings/{$building->id}");
        $manager->assertSee($create, false);
        $manager->assertSee($edit, false);
        $manager->assertDontSee($delete, false);

        $executive = $this->actingAs($this->executive())->get("/tenant/area-buildings/{$building->id}");
        $executive->assertSee($create, false);
        $executive->assertSee($edit, false);
        $executive->assertSee($delete, false);
    }

    /**
     * 行ごとの削除は confirm()（1 画面に複数対象があるので <x-delete-confirm-modal> は使えない。設計 §1-12）。
     * ⚠ JS 文字列への差し込みは Js::from()（生の {{ }} だと `'` を含むテナント名で壊れる）。
     *   テナント名は利用者の自由入力なので、ここは実際に `'` を含む名前で確かめる。
     */
    public function test_row_delete_uses_confirm_with_js_from(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => "O'Brien 商会"]);

        $html = $this->actingAs($this->executive())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $encoded = (string) Js::from($tenant->name);
        $this->assertStringContainsString(
            'onsubmit="return confirm(' . $encoded,
            $html,
            '行ごとの削除が confirm() + Js::from() になっていない'
        );
        // Js::from() は `'` を ' に逃がす。生の {{ }} や addslashes に戻すとここが赤になる
        $this->assertStringNotContainsString("O'Brien", $html, 'JS 文字列にテナント名が生で差し込まれている');
    }

    /** 名前が空の行（空き区画）でも確認ダイアログが成立すること */
    public function test_row_delete_of_a_nameless_row_has_a_fallback_label(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeTenant($building, ['room_number' => '201', 'status' => AreaTenantStatus::Vacant->value]);

        $html = $this->actingAs($this->executive())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $this->assertStringContainsString('onsubmit="return confirm(' . (string) Js::from('この行'), $html);
    }

    /**
     * 退去済みの行も編集・削除できること。
     *
     * ⚠ 退去日を入れた行は現況リストから外れる。折りたたみ側に操作を出さないと、
     *   **退去日の打ち間違いを二度と直せない**（編集画面への入口がここしか無い）。
     */
    public function test_moved_out_rows_keep_their_actions(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => '撤退カフェ', 'moved_out_on' => '2026-07-31']);

        $html = $this->actingAs($this->executive())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $this->assertStringContainsString('退去済み 1 件', $html, '退去済みの折りたたみが出ていない');
        $this->assertStringContainsString('href="' . route('tenant.area-buildings.tenants.edit', [$building, $tenant]) . '"', $html);

        $destroy = route('tenant.area-buildings.tenants.destroy', [$building, $tenant]);
        $form    = $this->parseForm($html, 'action="' . $destroy . '"');
        $this->assertSame('DELETE', $form['method']);

        // 退去日を消して現況へ戻せること（打ち間違いの復旧経路）
        $edit = $this->actingAs($this->executive())
            ->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->getContent();
        $editForm = $this->parseForm($edit, 'action="' . route('tenant.area-buildings.tenants.update', [$building, $tenant]) . '"');

        $this->assertSame('2026-07-31', $editForm['fields']['moved_out_on']);
        $editForm['fields']['moved_out_on'] = '';

        $this->actingAs($this->executive())->post($editForm['action'], $editForm['fields'])->assertRedirect();

        $this->assertNull($tenant->fresh()->moved_out_on);
        $this->assertSame(1, $building->activeTenants()->count());
    }

    /** 「操作」列を足したぶん colspan を合わせること（空表示が 1 列ずれない） */
    public function test_empty_tenant_table_spans_every_column(): void
    {
        $building = $this->makeBuilding('テナントなしビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $columns = $this->countTenantHeaderColumns($html);
        $this->assertSame(7, $columns, '入居テナントテーブルの列数が想定と違う');
        $this->assertStringContainsString('colspan="' . $columns . '"', $html, '空行の colspan が列数と合っていない');
    }

    /** <colgroup> の幅の合計が 100% であること（足し忘れると列幅が想定どおりに配分されない） */
    public function test_tenant_table_column_widths_add_up(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $widths = $this->tenantColumnWidths($html);
        $this->assertCount($this->countTenantHeaderColumns($html), $widths, '<col> の数が <th> の数と合っていない');
        $this->assertSame(100, array_sum($widths), '<colgroup> の幅の合計が 100% でない');
    }

    // ============================================================
    // 描画されたフォームの往復（Bug #47）
    //
    // ⚠ URL を assertSee で固定するだけでは配線の半分しか押さえられない。実測（Task 9）で
    //   `@method('PUT')` / `@method('DELETE')` を消しても、edit の action を store へ
    //   向けても、全テストが緑のままだった。描画したものをそのまま送り返す。
    // ============================================================

    /** 追加フォームを描画 → そのまま送信 → 1 件増えて詳細へ戻る */
    public function test_create_form_round_trips(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/tenants/create")->getContent();

        $store = route('tenant.area-buildings.tenants.store', $building);
        $form  = $this->parseForm($html, 'action="' . $store . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertSame($store, $form['action'], 'action が登録先を向いていない');
        // @csrf の欠落は挙動では検出できない（VerifyCsrfToken がテストでは素通りする）
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');
        $this->assertNotSame('', $form['fields']['_token']);

        // 未チェックの checkbox はブラウザも送らない → 詳細へ戻るのが既定
        $this->assertArrayNotHasKey('keep_adding', $form['fields'], '「続けて登録」が既定でチェック済みになっている');

        $form['fields']['name'] = '大街道珈琲';

        $this->actingAs($manager)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $tenant = AreaBuildingTenant::firstOrFail();
        $this->assertSame('大街道珈琲', $tenant->name);
        $this->assertSame(AreaTenantStatus::Operating, $tenant->status, '状態の既定が「営業」になっていない');
    }

    /**
     * 「保存して続けて登録」は**画面から**操作できて、次の追加画面でもチェックが残ること。
     *
     * ⚠ `keep_adding => '1'` を直接 POST するテストだけだと、チェックボックスを画面から
     *   消しても緑のまま通る（Bug #47「値を直接 POST していると画面から操作を消しても緑」）。
     * ⚠ チェックが残らないと 10〜20 区画の連続入力で毎回チェックし直すことになり、
     *   この機能の目的（往復を減らす。設計 §5.6）が果たせない。
     */
    public function test_keep_adding_can_be_checked_on_the_form_and_stays_checked(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();
        $create   = route('tenant.area-buildings.tenants.create', $building);

        $html = $this->actingAs($manager)->get($create)->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.tenants.store', $building) . '"');

        $this->assertStringContainsString('name="keep_adding"', $html, '「続けて登録」のチェックボックスが無い');

        // 画面でチェックを入れる（ブラウザは value 属性を送る）
        $form['fields']['keep_adding'] = $this->checkboxValue($html, 'keep_adding');
        $form['fields']['name']        = '1件目';

        $this->actingAs($manager)->post($form['action'], $form['fields'])->assertRedirect($create);

        // 戻ってきた追加画面ではチェックが残り、他の欄は空に戻っていること
        $next = $this->actingAs($manager)->get($create)->getContent();
        $back = $this->parseForm($next, 'action="' . route('tenant.area-buildings.tenants.store', $building) . '"');

        $this->assertArrayHasKey('keep_adding', $back['fields'], 'チェックが外れている（連続入力のたびに入れ直しになる）');
        $this->assertSame('', $back['fields']['name'], '前の入力が残っている');
    }

    /** 編集フォームを描画 → 何も触らずそのまま送信 → 内容が変わらず、レコードも増えない */
    public function test_edit_form_round_trips_unchanged(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, [
            'floor'        => -1,
            'room_number'  => 'B101',
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'status'       => AreaTenantStatus::Operating->value,
            'confirmed_on' => '2026-08-10',
            'notes'        => '角地の路面店',
        ]);
        $manager = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->getContent();

        $update = route('tenant.area-buildings.tenants.update', [$building, $tenant]);
        $form   = $this->parseForm($html, 'action="' . $update . '"');

        $this->assertSame('PUT', $form['method'], '@method(\'PUT\') が無い（送信すると 405 になる）');
        $this->assertSame($update, $form['action'], 'action が更新先を向いていない');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');
        $this->assertNotSame('', $form['fields']['_token']);
        $this->assertArrayNotHasKey('keep_adding', $form['fields'], '編集画面に「続けて登録」が出ている');

        $this->actingAs($manager)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(1, AreaBuildingTenant::count(), 'レコードが増えている（action が新規登録を向いている）');

        $fresh = $tenant->fresh();
        $this->assertSame(-1, $fresh->floor);
        $this->assertSame('B101', $fresh->room_number);
        $this->assertSame('大街道珈琲', $fresh->name);
        $this->assertSame('飲食', $fresh->industry);
        $this->assertSame(AreaTenantStatus::Operating, $fresh->status);
        $this->assertSame('2026-08-10', $fresh->confirmed_on->format('Y-m-d'));
        $this->assertNull($fresh->moved_out_on);
        $this->assertSame('角地の路面店', $fresh->notes);
    }

    /** 削除フォームを描画 → そのまま送信 → 実際に消える */
    public function test_delete_form_round_trips(): void
    {
        $building  = $this->makeBuilding('ミツワビル');
        $tenant    = $this->makeTenant($building, ['name' => '大街道珈琲']);
        $executive = $this->executive();

        $html = $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}")->getContent();

        $destroy = route('tenant.area-buildings.tenants.destroy', [$building, $tenant]);
        $form    = $this->parseForm($html, 'action="' . $destroy . '"');

        $this->assertSame('DELETE', $form['method'], '@method(\'DELETE\') が無い（押しても 405 で無反応）');
        $this->assertSame($destroy, $form['action']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        $this->actingAs($executive)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_tenants', ['id' => $tenant->id]);
    }

    /** 任意項目は**画面から**空に戻せること（空欄で送ったら旧値が残らない） */
    public function test_optional_fields_can_be_cleared_through_the_rendered_form(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, [
            'floor'        => 3,
            'room_number'  => '301',
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'confirmed_on' => '2026-08-10',
            'notes'        => '角地の路面店',
        ]);
        $manager = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}/edit")->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.tenants.update', [$building, $tenant]) . '"');

        foreach (['floor', 'room_number', 'name', 'industry', 'confirmed_on', 'notes'] as $name) {
            $this->assertArrayHasKey($name, $form['fields'], "{$name} の入力欄が画面に無い");
            $form['fields'][$name] = '';
        }

        $this->actingAs($manager)->post($form['action'], $form['fields'])->assertRedirect();

        $fresh = $tenant->fresh();
        foreach (['floor', 'room_number', 'name', 'industry', 'confirmed_on', 'notes'] as $name) {
            $this->assertNull($fresh->{$name}, "{$name} が空に戻せていない");
        }
    }

    /**
     * 送られてこなかった任意項目も旧値を残さない（Bug #38）。
     * フォームが描画している項目はフォームを正本にする — 未送信は「空にする」と解釈する。
     */
    public function test_fields_missing_from_the_request_are_not_left_behind(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, [
            'floor'    => 3,
            'name'     => '大街道珈琲',
            'industry' => '飲食',
            'notes'    => '角地の路面店',
        ]);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
                'status' => AreaTenantStatus::Vacant->value,
            ])->assertRedirect();

        $fresh = $tenant->fresh();
        $this->assertSame(AreaTenantStatus::Vacant, $fresh->status);
        $this->assertNull($fresh->floor);
        $this->assertNull($fresh->name);
        $this->assertNull($fresh->industry);
        $this->assertNull($fresh->notes);
    }

    /**
     * エラーで差し戻されても入力が残ること。
     * ⚠ このリポジトリは Bug #35 で「バリデーションエラーで入力が全消失する」を本番で踏んでいる。
     *   `$request->validate()` の自動 withInput と、ビュー側の `old(...)` の**両方**が
     *   生きていないと成立しない。
     */
    public function test_input_survives_a_validation_error(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();
        $create   = route('tenant.area-buildings.tenants.create', $building);

        $payload = [
            'floor'        => '3',
            'room_number'  => str_repeat('A', 51),   // ← これだけ不正。差し戻される
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'status'       => AreaTenantStatus::Vacant->value,
            'confirmed_on' => '2026-08-10',
            'moved_out_on' => '2026-09-30',
            'notes'        => '角地の路面店',
        ];

        $this->actingAs($manager)->from($create)
            ->post("/tenant/area-buildings/{$building->id}/tenants", $payload)
            ->assertRedirect($create);

        // 差し戻された画面を実際に描画して、入力が残っているかを見る
        $html = $this->actingAs($manager)->get($create)->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.tenants.store', $building) . '"');

        foreach ($payload as $name => $value) {
            $this->assertSame($value, $form['fields'][$name], "{$name} が差し戻し後に消えている（Bug #35）");
        }
    }

    /** 編集画面でも差し戻し後に入力が残ること（入口ごとに測る。Bug #44） */
    public function test_input_survives_a_validation_error_on_the_edit_screen(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, ['name' => '大街道珈琲']);
        $manager  = $this->manager();
        $edit     = route('tenant.area-buildings.tenants.edit', [$building, $tenant]);

        $this->actingAs($manager)->from($edit)
            ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}", [
                'name'     => '書き換え中',
                'industry' => str_repeat('あ', 101),   // ← これだけ不正
                'status'   => AreaTenantStatus::Unknown->value,
            ])->assertRedirect($edit);

        $html = $this->actingAs($manager)->get($edit)->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.tenants.update', [$building, $tenant]) . '"');

        $this->assertSame('書き換え中', $form['fields']['name'], '差し戻し後に保存済みの値へ戻っている（Bug #35）');
        $this->assertSame(str_repeat('あ', 101), $form['fields']['industry']);
        $this->assertSame(AreaTenantStatus::Unknown->value, $form['fields']['status'], 'セレクトが old() を見ていない');
        $this->assertSame('大街道珈琲', $tenant->fresh()->name, '不正な入力が保存された');
    }

    // ============================================================
    // バリデーション
    //
    // ⚠ Bug #40: SQLite は VARCHAR 長も INT の範囲も強制しないので、`max:50` などは
    //   **本番 MySQL（strict モード）専用の防波堤**。ルールを外すとテストは緑のまま通り、
    //   本番だけが 1406 Data too long で 500 になる。表で明示的に固定する。
    // ⚠ store / update の 2 入口を必ず両方回す（Bug #44）。
    // ============================================================

    public function test_invalid_input_is_rejected_on_both_entry_points(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $tenant   = $this->makeTenant($building, [
            'floor'        => 3,
            'room_number'  => '301',
            'name'         => '大街道珈琲',
            'industry'     => '飲食',
            'confirmed_on' => '2026-08-10',
        ]);
        $manager = $this->manager();

        $cases = [
            '状態が空'                => [['status' => ''], 'status'],
            '状態が未知の値'           => [['status' => 'closed'], 'status'],
            '階が下限より下'           => [['floor' => -11], 'floor'],
            '階が上限超'              => [['floor' => 201], 'floor'],
            '階が小数'                => [['floor' => 3.5], 'floor'],
            '階が数値でない'           => [['floor' => '三階'], 'floor'],
            '部屋番号が長すぎる'        => [['room_number' => str_repeat('A', 51)], 'room_number'],
            'テナント名が長すぎる'       => [['name' => str_repeat('あ', 256)], 'name'],
            '業種が長すぎる'           => [['industry' => str_repeat('あ', 101)], 'industry'],
            '最終確認日が日付でない'      => [['confirmed_on' => '2026-13-45'], 'confirmed_on'],
            '退去日が日付でない'         => [['moved_out_on' => 'あした'], 'moved_out_on'],
            '備考が長すぎる'           => [['notes' => str_repeat('あ', 2001)], 'notes'],
        ];

        $before = [
            $tenant->floor, $tenant->room_number, $tenant->name,
            $tenant->industry, $tenant->status, $tenant->confirmed_on->format('Y-m-d'),
        ];

        foreach ($cases as $label => [$override, $key]) {
            // --- store ---
            $response = $this->actingAs($manager)->from(route('tenant.area-buildings.tenants.create', $building))
                ->post("/tenant/area-buildings/{$building->id}/tenants",
                    array_merge(['status' => AreaTenantStatus::Operating->value], $override));

            // ⚠ 302 であることを必ず見る。ルールを外すと本番なら 500 だが SQLite では
            //   黙って保存されるので、「件数が増えていない」だけを見ると取りこぼす
            $response->assertStatus(302);
            $this->assertContains($key, $this->errorKeys(), "store / {$label}: {$key} のエラーが出ていない");
            $this->assertSame(1, $building->tenants()->count(), "store / {$label}: 不正なテナント行が作られた");

            // --- update ---
            $response = $this->actingAs($manager)->from(route('tenant.area-buildings.tenants.edit', [$building, $tenant]))
                ->put("/tenant/area-buildings/{$building->id}/tenants/{$tenant->id}",
                    array_merge(['status' => AreaTenantStatus::Operating->value], $override));

            $response->assertStatus(302);
            $this->assertContains($key, $this->errorKeys(), "update / {$label}: {$key} のエラーが出ていない");

            $fresh = $tenant->fresh();
            $this->assertSame(
                $before,
                [$fresh->floor, $fresh->room_number, $fresh->name,
                    $fresh->industry, $fresh->status, $fresh->confirmed_on->format('Y-m-d')],
                "update / {$label}: 不正な値が保存された"
            );
        }
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * バリデーションエラーのキー一覧。
     * ⚠ `session('errors')` は assertSessionHasErrors を通すまで生の配列なので両対応にする。
     *
     * @return list<string>
     */
    private function errorKeys(): array
    {
        $errors = session('errors');

        if (is_array($errors)) {
            return array_keys($errors['default']['messages'] ?? []);
        }

        return $errors === null ? [] : array_keys($errors->getBag('default')->getMessages());
    }

    /**
     * `<select ... name="X"> … </select>` を切り出す。
     * ⚠ ページ全体を対象にしたアサーションは、無関係な文字列（`x-form-actions` が
     *   `x-for` を部分文字列に含む等）に反応して誤って赤／緑になる。
     */
    private function extractSelect(string $html, string $name): string
    {
        $start = preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"/', $html, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : false;
        $this->assertNotFalse($start, "<select name=\"{$name}\"> が見つからない");

        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, "<select name=\"{$name}\"> が閉じていない");

        return substr($html, $start, $end - $start);
    }

    /** チェックボックスの value 属性（ブラウザがチェック時に送る値） */
    private function checkboxValue(string $html, string $name): string
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/i',
            $html,
            "name=\"{$name}\" の input が無い"
        );
        preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/i', $html, $m);
        $this->assertMatchesRegularExpression('/value="([^"]+)"/', $m[0], "{$name} に value 属性が無い");
        preg_match('/value="([^"]+)"/', $m[0], $v);

        return $v[1];
    }

    /**
     * 入居テナントテーブルの <thead> にある <th> の数。
     * ⚠ 同じページの調査履歴テーブルと構造がほぼ同じなので、テナント側の見出し語で位置を決める。
     */
    private function countTenantHeaderColumns(string $html): int
    {
        return preg_match_all('/<th\b/', $this->tenantHeaderRow($html));
    }

    /** 入居テナントテーブルの <colgroup> にある各列の幅（%） */
    private function tenantColumnWidths(string $html): array
    {
        $anchor = strpos($html, $this->tenantHeaderRow($html));
        $this->assertNotFalse($anchor);

        $start = strrpos(substr($html, 0, $anchor), '<colgroup>');
        $this->assertNotFalse($start, '入居テナントテーブルの <colgroup> が見つからない');

        $end = strpos($html, '</colgroup>', $start);
        $this->assertNotFalse($end, '<colgroup> が閉じていない');

        preg_match_all('/width:\s*(\d+)%/', substr($html, $start, $end - $start), $m);

        return array_map('intval', $m[1]);
    }

    /** 入居テナントテーブルの見出し行（<tr> … </tr>） */
    private function tenantHeaderRow(string $html): string
    {
        // 「部屋番号」は入居テナントテーブルの <thead> にしか出ない見出し語
        $anchor = strpos($html, '部屋番号');
        $this->assertNotFalse($anchor, '入居テナントテーブルの見出しが見つからない');

        $rowStart = strrpos(substr($html, 0, $anchor), '<tr>');
        $this->assertNotFalse($rowStart, '入居テナントテーブルの見出し行が見つからない');

        $rowEnd = strpos($html, '</tr>', $rowStart);
        $this->assertNotFalse($rowEnd, '入居テナントテーブルの見出し行が閉じていない');

        return substr($html, $rowStart, $rowEnd - $rowStart);
    }
}
