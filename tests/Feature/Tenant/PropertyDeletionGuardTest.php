<?php

namespace Tests\Feature\Tenant;

use App\Enums\InquiryStatus;
use App\Enums\RepairStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Investment;
use App\Models\Property;
use App\Models\PropertyChangeLog;
use App\Models\Repair;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\ScansModelRelations;
use Tests\TestCase;

/**
 * 関連データが残る物件は削除させない（docs/RULES.md Bug #59）。
 *
 * 物件は論理削除で、以前の歯止めは「契約中の契約がある」だけだった。区画・解約済み契約・投資・修繕・問合せが
 * 残ったまま物件を消せて、何も連鎖しない。子から物件へのリレーションは削除済みを読まないので、消した瞬間に
 * 投資・修繕・問合せの詳細、区画の詳細・編集・賃料改定、顧客詳細などが 500 になり、一覧からは黙って消えていた。
 *
 * 利用者の判断（2026-09-14）で「削除を止める」方式にした。守る不変条件は
 * 「削除済みの物件は、生きている区画・契約・投資・修繕・問合せを持たない」。
 * 使わなくなった物件は、稼働状態を「非稼働」にして残す。
 */
class PropertyDeletionGuardTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use ScansModelRelations;

    private const ADVICE = '使わなくなった物件は、編集画面で稼働状態を「非稼働」にしてください。';

    private Property $building;

    /**
     * 論理削除済みの区画。契約・投資は区画が必須（NOT NULL）なので、1 種類ずつ測るときはこれを指させる
     * （生きている区画を指させると「区画 1 件」も一緒に数えられてしまう）。
     */
    private Unit $removedUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->building = Property::create([
            'code' => 'T-GUARD-1', 'name' => '削除候補ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);
        $this->removedUnit = $this->unit(2, 'A');
        $this->removedUnit->delete();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function unit(int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $this->building->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'vacant',
            'area_tsubo' => 10,
        ]);
    }

    private function contract(string $status, ?Unit $unit = null): Contract
    {
        static $seq = 0;
        $seq++;
        $customer = Customer::create(['code' => "CU-GUARD-{$seq}", 'name' => "削除候補商事{$seq}", 'customer_type' => 'corporation']);

        return Contract::create([
            'contract_number' => "C-GUARD-{$seq}",
            'department' => 'tenant',
            'property_id' => $this->building->id,
            'unit_id' => ($unit ?? $this->removedUnit)->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'contract_date' => '2025-04-01',
            'rent_start_date' => '2025-04-01',
            'contract_end_date' => $status === 'terminated' ? '2026-03-31' : null,
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    private function investment(): Investment
    {
        return Investment::create([
            'investment_number' => 'INV-GUARD-1',
            'property_id' => $this->building->id,
            'unit_id' => $this->removedUnit->id,
            'pattern' => 'renovation',
            'status' => 'planning',
            'description' => '削除候補ビルの投資',
            'total_amount' => 1000000,
        ]);
    }

    private function repair(): Repair
    {
        return Repair::create([
            'property_id' => $this->building->id,
            'unit_id' => null,
            'status' => RepairStatus::Planned->value,
            'description' => '削除候補ビルの修繕',
        ]);
    }

    private function inquiry(): Inquiry
    {
        return Inquiry::create([
            'inquiry_number' => 'INQ-GUARD-1',
            'property_id' => $this->building->id,
            'contact_name' => '削除 花子',
            'inquiry_date' => '2026-09-01',
            'status' => InquiryStatus::Follow->value,
        ]);
    }

    private function refusal(string $blockers): string
    {
        return "この物件には{$blockers}があるため削除できません。" . self::ADVICE;
    }

    /** 物件詳細の「削除」から送ったのと同じ経路（戻り先は物件詳細） */
    private function destroy(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())
            ->from(route('tenant.properties.show', $this->building))
            ->delete(route('tenant.properties.destroy', $this->building));
    }

    private function assertRefused(string $blockers): void
    {
        $this->destroy()
            ->assertRedirect(route('tenant.properties.show', $this->building))
            ->assertSessionHas('error', $this->refusal($blockers));

        $this->assertNotSoftDeleted($this->building);
    }

    private function assertDeleted(): void
    {
        $this->destroy()
            ->assertRedirect(route('tenant.properties.index'))
            ->assertSessionHas('success', '物件「削除候補ビル」を削除しました。');

        $this->assertSoftDeleted($this->building);
    }

    // ============================================================
    // 種類ごとに止まる（Bug #44: 1 種類ずつ全部測る。代表だけ測ると残りの数え漏れを検出できない）
    // ============================================================

    public function test_a_live_unit_blocks_the_deletion(): void
    {
        $this->unit(1, 'A');

        $this->assertRefused('区画 1 件');
    }

    public function test_an_active_contract_blocks_the_deletion(): void
    {
        $this->contract('active');

        $this->assertRefused('契約 1 件');
    }

    public function test_a_terminated_contract_blocks_the_deletion(): void
    {
        // 以前の歯止めは契約中の契約しか見ていなかった
        $this->contract('terminated');

        $this->assertRefused('契約 1 件');
    }

    public function test_an_investment_blocks_the_deletion(): void
    {
        $this->investment();

        $this->assertRefused('投資 1 件');
    }

    public function test_a_repair_blocks_the_deletion(): void
    {
        $this->repair();

        $this->assertRefused('修繕 1 件');
    }

    public function test_an_inquiry_blocks_the_deletion(): void
    {
        $this->inquiry();

        $this->assertRefused('問合せ 1 件');
    }

    public function test_the_refusal_counts_every_kind_in_a_fixed_order(): void
    {
        $unit = $this->unit(1, 'A');
        $this->unit(3, 'A');
        $this->contract('active', $unit);
        $this->contract('terminated', $unit);
        $this->investment();
        $this->repair();
        $this->inquiry();

        $this->assertRefused('区画 2 件・契約 2 件・投資 1 件・修繕 1 件・問合せ 1 件');
    }

    // ============================================================
    // 止めないもの
    // ============================================================

    public function test_soft_deleted_children_do_not_block_the_deletion(): void
    {
        // 区画は setUp の削除済み区画がある
        $this->contract('active')->delete();
        $this->contract('terminated')->delete();
        $this->investment()->delete();
        $this->repair()->delete();
        $this->inquiry()->delete();

        $this->assertDeleted();
    }

    public function test_change_logs_transactions_and_attachments_do_not_block_the_deletion(): void
    {
        // 変更履歴は物件ページでしか出ない・取引は画面が無い・物件に添付する経路が無い（Property::DELETION_IGNORED_RELATIONS）
        $user = $this->executive();
        PropertyChangeLog::create([
            'property_id' => $this->building->id, 'field_name' => '稼働状態', 'old_value' => '稼働', 'new_value' => '非稼働',
            'changed_by' => $user->id, 'changed_at' => now(),
        ]);
        Transaction::create([
            'department' => 'tenant', 'transaction_type' => 'income', 'transaction_date' => '2026-04-01',
            'accounting_ym' => '2026-04', 'category' => '賃料', 'amount_excl_tax' => 100000, 'amount_incl_tax' => 100000,
            'property_id' => $this->building->id,
        ]);
        $this->building->attachments()->create([
            'file_name' => '図面.pdf', 'file_path' => 'attachments/zumen.pdf', 'file_size' => 100, 'mime_type' => 'application/pdf',
        ]);

        $this->assertDeleted();
    }

    public function test_a_property_without_related_data_is_deleted(): void
    {
        $this->assertDeleted();
    }

    // ============================================================
    // 画面からの往復（Bug #47: 描画された削除フォームをそのまま送り返す）
    // ============================================================

    public function test_the_delete_button_shows_the_refusal_in_the_error_banner(): void
    {
        $this->unit(1, 'A');
        $this->investment();
        $user = $this->executive();

        $show = $this->actingAs($user)->get(route('tenant.properties.show', $this->building))->assertOk();
        $form = $this->parseForm($show->getContent(), 'action="' . route('tenant.properties.destroy', $this->building) . '"');
        $this->assertSame('DELETE', $form['method'], '削除フォームが DELETE で送られない');
        $this->assertArrayHasKey('_token', $form['fields'], '削除フォームに @csrf が無い');

        $this->actingAs($user)
            ->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.properties.show', $this->building));
        $this->assertNotSoftDeleted($this->building);

        // ⚠ ここまでセッションに触らない（Bug #49: assertSessionHas* を呼ぶとフラッシュが消費され、次の描画から消える）
        $html = $this->actingAs($user)->get(route('tenant.properties.show', $this->building))->assertOk()->getContent();

        // ページ全体への assertSee にしない（Bug #43 / #46）。レイアウトの赤帯の要素の中にちょうど 1 回
        $banner = '<span class="text-sm text-red-800">' . e($this->refusal('区画 1 件・投資 1 件')) . '</span>';
        $this->assertSame(1, substr_count($html, $banner), '赤帯に削除できない理由が出ていない');
    }

    // ============================================================
    // 登録・更新で削除済みの物件を受け付けない（exists は論理削除を見ないので withoutTrashed() を明示する）
    //
    // 画面は削除済みの物件を選択肢に出さないので、届くのは「画面を開いたあとに物件が削除された」送信か手組みの送信。
    // ⚠ 投資・契約は区画の所属チェックが後ろにあり、ふつうの状態（削除済みの物件は区画を持たない）だと
    //   そちらが弾いてしまい、入力チェックを外しても緑のまま通る（Bug #48）。そこで歯止めを通さずに
    //   物件を直接 delete() して、その下に生きている区画を残した状態を作り、新しいルールだけが止める形にする。
    // ============================================================

    private const MISSING_PROPERTY = '選択された物件は存在しません。';

    /** @return array{0: Property, 1: Unit} 削除済みの物件と、その下に残した生きている区画 */
    private function deletedPropertyWithLiveUnit(): array
    {
        $gone = Property::create([
            'code' => 'T-GUARD-9', 'name' => '削除済みビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 2,
        ]);
        $unit = Unit::create([
            'property_id' => $gone->id, 'floor' => 1, 'room_number' => 'A', 'display_name' => '1A',
            'status' => 'vacant', 'area_tsubo' => 10,
        ]);
        $gone->delete();

        return [$gone, $unit];
    }

    public function test_contract_store_rejects_a_deleted_property(): void
    {
        [$gone, $unit] = $this->deletedPropertyWithLiveUnit();
        $customer = Customer::create(['code' => 'CU-GUARD-9', 'name' => '手組み商事', 'customer_type' => 'corporation']);

        $this->actingAs($this->executive())
            ->post(route('tenant.contracts.store'), [
                'property_id' => $gone->id,
                'unit_id' => $unit->id,
                'customer_id' => $customer->id,
                'contract_date' => '2026-09-01',
                'rent' => 100000,
                'initial_month_type' => 'full',
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $this->assertSame(0, Contract::count());
    }

    public function test_investment_store_rejects_a_deleted_property(): void
    {
        [$gone, $unit] = $this->deletedPropertyWithLiveUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.investments.store'), [
                'property_id' => $gone->id,
                'unit_id' => $unit->id,
                'pattern' => 'renovation',
                'status' => 'planning',
                'details' => [['cost_item' => 'interior', 'amount' => 1000000]],
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $this->assertSame(0, Investment::count());
    }

    public function test_investment_update_rejects_a_deleted_property(): void
    {
        [$gone, $unit] = $this->deletedPropertyWithLiveUnit();
        $investment = $this->investment();

        $this->actingAs($this->executive())
            ->put(route('tenant.investments.update', $investment), [
                'property_id' => $gone->id,
                'unit_id' => $unit->id,
                'pattern' => 'renovation',
                'status' => 'in_progress',
                'details' => [['cost_item' => 'interior', 'amount' => 1000000]],
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $investment->refresh();
        $this->assertSame([$this->building->id, 'planning'], [$investment->property_id, $investment->status->value]);
    }

    public function test_repair_store_rejects_a_deleted_property(): void
    {
        [$gone] = $this->deletedPropertyWithLiveUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.repairs.store'), [
                'property_id' => $gone->id,
                'unit_id' => '',
                'status' => RepairStatus::Planned->value,
                'description' => '手組みの修繕',
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $this->assertSame(0, Repair::count());
    }

    public function test_repair_update_rejects_a_deleted_property(): void
    {
        [$gone] = $this->deletedPropertyWithLiveUnit();
        $repair = $this->repair();

        $this->actingAs($this->executive())
            ->put(route('tenant.repairs.update', $repair), [
                'property_id' => $gone->id,
                'unit_id' => '',
                'status' => RepairStatus::InProgress->value,
                'description' => '手組みの修繕',
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $repair->refresh();
        $this->assertSame([$this->building->id, RepairStatus::Planned], [$repair->property_id, $repair->status]);
    }

    public function test_inquiry_store_rejects_a_deleted_property(): void
    {
        [$gone] = $this->deletedPropertyWithLiveUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.inquiries.store'), [
                'property_id' => $gone->id,
                'inquiry_date' => '2026-09-02',
                'contact_name' => '手組み 太郎',
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_inquiry_update_rejects_a_deleted_property(): void
    {
        [$gone] = $this->deletedPropertyWithLiveUnit();
        $inquiry = $this->inquiry();

        $this->actingAs($this->executive())
            ->put(route('tenant.inquiries.update', $inquiry), [
                'property_id' => $gone->id,
                'unit_ids' => [],
                'inquiry_date' => '2026-09-02',
                'contact_name' => '手組み 太郎',
            ])
            ->assertSessionHasErrors(['property_id' => self::MISSING_PROPERTY]);

        $inquiry->refresh();
        $this->assertSame([$this->building->id, '削除 花子'], [$inquiry->property_id, $inquiry->contact_name]);
    }

    // ============================================================
    // 構造: 物件のリレーションを両側から全件分類する（Top trap #13。数え漏れを「リレーションを足した日」に止める）
    // ============================================================

    private const RELATION_CALL = '/\$this->(belongsTo|belongsToMany|hasMany|hasOne|hasManyThrough|hasOneThrough|morphMany|morphOne|morphTo|morphToMany|morphedByMany)\(/';

    /** 分類済みのリレーション名（止める ＋ 止めない） */
    private function classifiedRelations(): array
    {
        return array_merge(array_keys(Property::DELETION_BLOCKING_RELATIONS), array_keys(Property::DELETION_IGNORED_RELATIONS));
    }

    /**
     * 物件の側: app/Models/Property.php に書かれたリレーションが、削除を「止める」「止めない」のちょうど 1 つに入っていること。
     * 新しいリレーションを足した日にここで落ち、分類を求められる（止める側に入れれば deletionBlockers() が数える）。
     */
    public function test_every_relation_of_property_is_classified_for_deletion(): void
    {
        $found = [];
        foreach ((new \ReflectionClass(Property::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // trait のメソッド（SoftDeletes など）を除くため、宣言元のクラスでなくファイルで判定する
            // （getDeclaringClass() は trait のメソッドでも使う側のクラスを返す）
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                || $method->getFileName() !== app_path('Models/Property.php')) {
                continue;
            }
            if (preg_match(self::RELATION_CALL, $this->methodSourceWithoutComments($method))) {
                $found[] = $method->getName();
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（2026-09-14 時点で止める 5 本 ＋ 止めない 3 本）。
        // ⚠ 先に見る。後ろに置くと、空振りが「リストに実在しない名前が残っている」という別の理由で報告される
        $this->assertGreaterThanOrEqual(8, count($found), 'Property のリレーションを拾えていない: ' . implode(', ', $found));

        $blocking = array_keys(Property::DELETION_BLOCKING_RELATIONS);
        $ignored = array_keys(Property::DELETION_IGNORED_RELATIONS);
        $this->assertSame([], array_values(array_intersect($blocking, $ignored)), '削除を「止める」と「止めない」の両方に入っているリレーションがある');
        $this->assertSame(
            [],
            array_values(array_diff($found, $this->classifiedRelations())),
            'Property のリレーションに、削除を止めるか止めないかの分類が無いものがある（DELETION_BLOCKING_RELATIONS か DELETION_IGNORED_RELATIONS に足すこと）'
        );
        $this->assertSame([], array_values(array_diff($this->classifiedRelations(), $found)), '分類のリストに、Property に無いリレーションの名前が残っている');
    }

    /**
     * 子の側: 物件を指すリレーションを持つモデルが、どれも分類済みのリレーションの相手であること。
     * 子モデルに property() だけ足して物件の側に hasMany を足さないと、上の走査には現れず、削除の歯止めが数え漏らす。
     */
    public function test_every_model_pointing_at_property_is_covered_by_a_classified_relation(): void
    {
        $covered = array_map(fn (string $relation) => get_class((new Property)->{$relation}()->getRelated()), $this->classifiedRelations());

        $children = [];
        foreach ($this->modelClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $file = $method->getFileName();
                if ($class === Property::class || $method->isStatic() || $file === false || ! str_starts_with($file, app_path('Models'))) {
                    continue;
                }
                $source = $this->methodSourceWithoutComments($method);
                // HsProperty::class / MsProperty::class には当てない（直前が語の文字なら別のクラス）
                if (preg_match('/(?:\\\\App\\\\Models\\\\|(?<![\w\\\\]))Property::class/', $source) && preg_match(self::RELATION_CALL, $source)) {
                    $children[$class] = true;
                }
            }
        }
        $children = array_keys($children);

        $this->assertSame(
            [],
            array_values(array_diff($children, $covered)),
            '物件を指すのに、物件の側で削除を止めるか止めないかを分類していないモデルがある（Property にリレーションを足して分類すること）'
        );

        // 走査が空振りして緑になる事故を防ぐ（2026-09-14 時点で Unit / Contract / Investment / Repair / Inquiry / Transaction / PropertyChangeLog）
        $this->assertGreaterThanOrEqual(7, count($children), '物件を指すモデルを拾えていない: ' . implode(', ', $children));
    }
}
