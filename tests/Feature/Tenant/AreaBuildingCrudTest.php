<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class AreaBuildingCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /**
     * ⚠ /create が {building} の ID として解釈されないこと（設計 §5.1 / §11-10）。
     *   ルート宣言の順序が逆だとモデルバインディングで 404 になる。
     */
    public function test_create_route_is_not_swallowed_by_the_show_route(): void
    {
        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings/create');

        $response->assertOk();
        $response->assertViewIs('tenant.area-buildings.create');
    }

    public function test_unknown_id_is_404(): void
    {
        $this->actingAs($this->staff())->get('/tenant/area-buildings/999999')->assertNotFound();
    }

    // ============================================================
    // 権限（設計 §8）
    // ============================================================

    public function test_staff_cannot_reach_create_store_edit_update_or_destroy(): void
    {
        $building = $this->makeBuilding('既存ビル');
        $staff    = $this->staff();

        $this->actingAs($staff)->get('/tenant/area-buildings/create')->assertForbidden();
        $this->actingAs($staff)->post('/tenant/area-buildings', ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/edit")->assertForbidden();
        $this->actingAs($staff)->put("/tenant/area-buildings/{$building->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}")->assertForbidden();
    }

    public function test_manager_can_edit_but_cannot_delete(): void
    {
        $building = $this->makeBuilding('既存ビル');
        $manager  = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$building->id}/edit")->assertOk();
        $this->actingAs($manager)->delete("/tenant/area-buildings/{$building->id}")->assertForbidden();
    }

    public function test_executive_can_delete_and_it_is_soft(): void
    {
        $building = $this->makeBuilding('消すビル');

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}")
            ->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSoftDeleted('area_buildings', ['id' => $building->id]);
    }

    /**
     * executive も create/store/edit/update に到達できること（設計 §8）。
     *
     * ⚠ コード品質レビュー（2026-08-17）で指摘: destroy 以外の 4 操作は manager でしか
     *   テストしておらず、`role:executive,manager` ミドルウェアから executive が
     *   誤って外れても検出できなかった（実際に叩けば通ることは確認済みだったが、
     *   将来の変更を検出する回帰テストが無かった）。create/store 用と edit/update 用の
     *   ミドルウェアグループは routes/web.php 上で 2 箇所に分かれているため、
     *   片方だけ role:manager に変異させても本テストが両方とも拾えることを個別に確認済み。
     */
    public function test_executive_can_also_create_store_edit_and_update(): void
    {
        $executive = $this->executive();
        $building  = $this->makeBuilding('既存ビル');

        $this->actingAs($executive)->get('/tenant/area-buildings/create')->assertOk();

        $this->actingAs($executive)->post('/tenant/area-buildings', [
            'name' => 'executive登録ビル',
        ])->assertRedirect();
        $this->assertTrue(AreaBuilding::where('name', 'executive登録ビル')->exists());

        $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}/edit")->assertOk();

        $this->actingAs($executive)->put("/tenant/area-buildings/{$building->id}", [
            'name' => '新名(executive)',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));
        $this->assertSame('新名(executive)', $building->fresh()->name);
    }

    // ============================================================
    // 登録
    // ============================================================

    public function test_store_creates_a_building_and_records_the_creator(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/tenant/area-buildings', [
            'name'         => '新規ビル',
            'address'      => '愛媛県松山市大街道1-1',
            'total_floors' => 6,
            'latitude'     => '33.8392000',
            'longitude'    => '132.7657000',
            'notes'        => 'メモ',
        ])->assertRedirect();

        $building = AreaBuilding::where('name', '新規ビル')->firstOrFail();
        $this->assertSame('愛媛県松山市大街道1-1', $building->address);
        $this->assertSame(6, $building->total_floors);
        $this->assertSame($manager->id, $building->created_by);
        $this->assertSame(0, $building->surveys()->count(), '調査年月が空なのに調査回が作られている');
    }

    /** 新規登録時のみ、同じ画面で 1 回目の調査も作れる（設計 §5.5） */
    public function test_store_can_create_the_first_survey_at_the_same_time(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/tenant/area-buildings', [
            'name'            => '初回調査つきビル',
            'surveyed_month'  => '2026-08',
            'operating_count' => 7,
            'vacant_count'    => 2,
            'unknown_count'   => 1,
            'survey_notes'    => '1階は改装中',
        ])->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame([7, 2, 1], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
        $this->assertSame('1階は改装中', $survey->notes);
        $this->assertSame($manager->id, $survey->surveyed_by, '調査者の既定がログインユーザーになっていない');
    }

    /**
     * store() が AreaBuilding + AreaBuildingSurvey の 2 書き込みをトランザクションで囲んでいること
     * （コード品質レビュー Important I-1、2026-08-17）。
     *
     * ⚠ UNIQUE 制約違反ではない。store() は毎回新規採番するので、直後に作る調査回が
     *   既存行と衝突することは原理的に無い。実際に起こりうるのは DB 接続断・デッドロック・
     *   ディスク枯渇のような汎用的な失敗で、それを模すためモデルイベントで例外を発生させる。
     * ⚠ 登録した listener は他のテストへ漏れないよう try/finally で確実に外す。
     */
    public function test_store_rolls_back_the_building_if_the_survey_creation_fails(): void
    {
        AreaBuildingSurvey::creating(function () {
            throw new \RuntimeException('simulated failure between the two creates (I-1 regression test)');
        });

        try {
            $this->actingAs($this->manager())->post('/tenant/area-buildings', [
                'name'            => '途中失敗ビル',
                'surveyed_month'  => '2026-08',
                'operating_count' => 5,
            ]);
        } finally {
            Event::forget('eloquent.creating: ' . AreaBuildingSurvey::class);
        }

        $this->assertSame(
            0,
            AreaBuilding::count(),
            'トランザクションが無いため、調査回の作成失敗時にビルだけが残っている'
        );
    }

    /** 件数欄は空欄スタート。未入力は 0 として保存する（設計 §5.5） */
    public function test_blank_counts_are_saved_as_zero(): void
    {
        $this->actingAs($this->manager())->post('/tenant/area-buildings', [
            'name'           => '件数空欄ビル',
            'surveyed_month' => '2026-08',
        ])->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame([0, 0, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    public function test_name_is_required_and_the_error_says_building_name_in_japanese(): void
    {
        $response = $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/create')
            ->post('/tenant/area-buildings', ['name' => '']);

        $response->assertRedirect('/tenant/area-buildings/create');
        // ⚠ プランは 'ビル名は必ず入力してください。' としていたが、lang/ja/validation.php の
        //   required は ':attributeは必須です。'（他画面も全て「は必須です。」。
        //   JapaneseValidationMessagesTest 参照）。実測に合わせて修正した（2026-08-17）。
        $response->assertSessionHasErrors(['name' => 'ビル名は必須です。']);
    }

    /** 所在地はグローバルの「住所」でなく画面ラベルの「所在地」（Bug #37） */
    public function test_address_error_says_shozaichi_not_juusho(): void
    {
        $response = $this->actingAs($this->manager())
            ->from('/tenant/area-buildings/create')
            ->post('/tenant/area-buildings', ['name' => 'X', 'address' => str_repeat('あ', 256)]);

        $response->assertSessionHasErrorsIn('default', ['address']);
        $this->assertStringContainsString('所在地', session('errors')->first('address'));
        $this->assertStringNotContainsString('住所', session('errors')->first('address'));
    }

    // ============================================================
    // 更新
    // ============================================================

    public function test_update_changes_the_building_and_never_touches_surveys(): void
    {
        $building = $this->makeBuilding('旧名', ['address' => '旧住所']);
        $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->manager())->put("/tenant/area-buildings/{$building->id}", [
            'name'    => '新名',
            'address' => '新住所',
            // ⚠ 編集画面に調査欄は出さない。送っても無視されることを固定する
            'surveyed_month'  => '2026-09',
            'operating_count' => 99,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $building->refresh();
        $this->assertSame('新名', $building->name);
        $this->assertSame('新住所', $building->address);
        $this->assertSame(1, $building->surveys()->count(), '編集で調査回が増えている');
        $this->assertSame(5, $building->surveys()->first()->operating_count);
    }

    /**
     * 座標入りのビルを編集し、空欄で送ると座標が null になること
     * （コード品質レビュー Important I-2、2026-08-17。Bug #38 の裏返し―
     *   あちらは「消えるべき値が残った」、こちらは「意図せず消えうる」。
     *   どちらも「条件付きで値が消える/残る」が無音で壊れるパターンなので固定する）。
     *
     * ⚠ 空文字 '' は ConvertEmptyStringsToNull ミドルウェア（web グループ既定）で
     *   実 HTTP では null に正規化されてから validate() に届く。
     */
    public function test_update_can_clear_the_coordinates(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $this->actingAs($this->manager())->put("/tenant/area-buildings/{$building->id}", [
            'name'      => $building->name,
            'latitude'  => '',
            'longitude' => '',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
    }

    /**
     * update() 経路でも「所在地」であること(Bug #37。store() 側は
     * test_address_error_says_shozaichi_not_juusho で固定済み)。
     *
     * ⚠ プランの変異表 Step 10-#3 は「store() と update() の第3引数から
     *   'address' => '所在地' を削除(2 箇所とも)」で
     *   test_address_error_says_shozaichi_not_juusho が赤になるとしていたが、
     *   実測するとその既存テストは POST(store())しか叩いておらず、
     *   update() 側だけを削っても既存テストは緑のまま残ることを確認した
     *   (プラン自身の注記「片方だけ消すと、もう片方の経路を叩くテストが緑のまま残る」が
     *   指す穴を実際に踏んだ形。2026-08-17 に本テストを追加して塞いだ)。
     */
    public function test_update_address_error_says_shozaichi_not_juusho(): void
    {
        $building = $this->makeBuilding('既存ビル');

        $response = $this->actingAs($this->manager())
            ->from("/tenant/area-buildings/{$building->id}/edit")
            ->put("/tenant/area-buildings/{$building->id}", [
                'name'    => 'X',
                'address' => str_repeat('あ', 256),
            ]);

        $response->assertSessionHasErrorsIn('default', ['address']);
        $this->assertStringContainsString('所在地', session('errors')->first('address'));
        $this->assertStringNotContainsString('住所', session('errors')->first('address'));
    }

    /** 編集フォームは保存済みの座標を hidden に載せる（地図の初期表示に使う） */
    public function test_edit_form_carries_the_saved_coordinates(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/edit")
            ->assertOk()
            ->assertSee('value="33.8392000"', false)
            ->assertSee('value="132.7657000"', false);
    }

    // ============================================================
    // 地図（費用最小化。設計 §6.0）
    // ============================================================

    /** ⚠ Street View のコントロールを出すと、開いた回数だけ課金される */
    public function test_form_disables_street_view_control(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        $this->assertStringContainsString('streetViewControl: false', $html);
        $this->assertStringNotContainsString('streetViewControl: true', $html);
    }

    /** 地図は「マップで確認」を押したときだけ生成する（読み込んだだけでは new しない） */
    public function test_form_creates_the_map_only_on_demand(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings/create')->getContent();

        // bootstrap は読み込む（Geocoder が要る）
        $this->assertStringContainsString('maps.googleapis.com/maps/api/js', $html);

        // new google.maps.Map は showAreaMap() の中だけ。onGoogleMapsReady では作らない
        $this->assertSame(1, substr_count($html, 'new google.maps.Map('), 'Map を生成する箇所が 1 つでない');
    }

    /**
     * 所在地を送らない更新で、既存の住所が消えないこと。
     *
     * ⚠ 2026-08-19 に所在地の入力欄を画面から外したので、**実運用の編集は必ずこの形**になる。
     *   `'address' => $validated['address'] ?? null` のままだと、Excel 取込で入った住所が
     *   編集保存のたびに NULL へ落ちる（Bug #38 と同型。画面に出ないので誰も気づけない）。
     */
    public function test_update_without_address_keeps_the_existing_address(): void
    {
        $building = $this->makeBuilding('取込で入った棟', ['address' => '愛媛県松山市一番町1-1']);

        $this->actingAs($this->manager())
            ->put('/tenant/area-buildings/' . $building->id, [
                'name'         => '取込で入った棟',
                'total_floors' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(
            '愛媛県松山市一番町1-1',
            $building->refresh()->address,
            '所在地を送らない更新で既存の住所が消えている'
        );
    }

    /** 送れば今までどおり更新できる（サーバ側の受け口は残してある。設計書 §6.2） */
    public function test_update_with_address_still_updates_it(): void
    {
        $building = $this->makeBuilding('住所を直す棟', ['address' => '旧住所']);

        $this->actingAs($this->manager())
            ->put('/tenant/area-buildings/' . $building->id, [
                'name'    => '住所を直す棟',
                'address' => '新住所',
            ])
            ->assertRedirect();

        $this->assertSame('新住所', $building->refresh()->address);
    }
}
